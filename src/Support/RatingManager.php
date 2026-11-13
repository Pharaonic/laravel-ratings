<?php

namespace Pharaonic\Laravel\Ratings\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pharaonic\Laravel\Ratings\Events\RatingCreated;
use Pharaonic\Laravel\Ratings\Events\RatingRemoved;
use Pharaonic\Laravel\Ratings\Events\RatingUpdated;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Relations\RatingsMorphMany;
use RuntimeException;
use Stringable;
use UnexpectedValueException;

/**
 * The one place that reads and writes ratings, shared by CanRate and HasRatings.
 *
 * Every write runs in a transaction on the rating model's connection. Outside
 * a caller's transaction, Laravel runs it again when the database reports a
 * deadlock or a locked SQLite file, up to ATTEMPTS times; inside one, the
 * error goes to the caller, because the database already rolled the caller's
 * transaction back. Events are dispatched after the write's own transaction,
 * so inside a caller's transaction they come before that transaction commits.
 *
 * @internal
 */
final class RatingManager
{
    /**
     * The longest morph type the ratings table stores.
     */
    public const TYPE_LENGTH = 191;

    /**
     * The longest key the ratings table stores.
     */
    public const KEY_LENGTH = 64;

    /**
     * The longest comment, in bytes: what a MySQL TEXT column holds.
     */
    public const COMMENT_BYTES = 65535;

    /**
     * How many times a write runs when a concurrent request keeps getting in its way.
     */
    private const ATTEMPTS = 3;

    /**
     * The rateable of each rating this class is saving, by object id, so the
     * rating's scale check does not load the model it already has.
     *
     * @var array<int, Model>
     */
    private static array $saving = [];

    /**
     * The configured rating model class.
     *
     * @return class-string<Rating>
     *
     * @throws UnexpectedValueException when pharaonic.ratings.model is not Rating or a subclass
     */
    public static function modelClass(): string
    {
        $class = Config::get('pharaonic.ratings.model');

        if ($class === null || $class === '') {
            return Rating::class;
        }

        if (! is_string($class) || ! is_a($class, Rating::class, true)) {
            throw new UnexpectedValueException(sprintf(
                'The pharaonic.ratings.model setting must name %s or a subclass of it, not [%s].',
                Rating::class, is_string($class) ? $class : get_debug_type($class)
            ));
        }

        return $class;
    }

    /**
     * A new instance of the configured rating model.
     */
    public static function newModel(): Rating
    {
        $class = self::modelClass();

        return new $class;
    }

    /**
     * Create or update the pair's rating.
     *
     * The comment is only written when $commentGiven: then a string sets it
     * and null clears it; otherwise an existing comment is kept and a new
     * rating has none.
     *
     * @throws InvalidArgumentException when a model is unsaved, or the value or comment is not valid
     * @throws UnexpectedValueException when the rateable's scale is not valid
     * @throws RuntimeException when a model event cancels the save, or the row keeps being deleted under the write
     */
    public static function rate(Model $rater, Model $rateable, mixed $value, bool $commentGiven, mixed $comment): RatingResult
    {
        $pair = self::pair($rater, $rateable);
        $value = RatingScale::normalize($value);
        RatingScale::for($rateable)->assertAllows($value, $rateable);
        $comment = $commentGiven ? self::comment($comment) : null;

        $result = self::writeInTransactions($pair, $rateable, $value, $commentGiven, $comment);

        [$status, $rating, $previousValue, $previousComment] = $result;

        if ($status === 'created') {
            Event::dispatch(new RatingCreated($rating, $rater, $rateable));
        } elseif ($status === 'updated' && $previousValue !== null) {
            Event::dispatch(new RatingUpdated($rating, $rater, $rateable, $previousValue, $previousComment));
        }

        return new RatingResult($status, $rating, $previousValue, RatingScale::toNumber($value));
    }

    /**
     * Remove the pair's rating. True when this call deleted it.
     *
     * @throws InvalidArgumentException when a model is unsaved
     */
    public static function unrate(Model $rater, Model $rateable): bool
    {
        $rating = self::find(self::pair($rater, $rateable));

        if ($rating === null) {
            return false;
        }

        $deleted = (bool) $rating->getConnection()->transaction(function () use ($rating) {
            return self::delete($rating);
        }, self::ATTEMPTS);

        if ($deleted) {
            Event::dispatch(new RatingRemoved($rating, $rater, $rateable));
        }

        return $deleted;
    }

    /**
     * The pair's rating, or null. Null when either model is unsaved.
     */
    public static function of(Model $rater, Model $rateable): ?Rating
    {
        if (! self::isPersisted($rater) || ! self::isPersisted($rateable)) {
            return null;
        }

        return self::find(self::pair($rater, $rateable));
    }

    /**
     * Whether the rater rated the rateable. False when either one is unsaved.
     */
    public static function has(Model $rater, Model $rateable): bool
    {
        if (! self::isPersisted($rater) || ! self::isPersisted($rateable)) {
            return false;
        }

        return self::unscoped()->where(self::pair($rater, $rateable))->exists();
    }

    /**
     * The ratings given (rater) or received (rateable) by the model.
     */
    public static function relation(Model $parent, string $side): MorphMany
    {
        $rating = self::newModel();
        $table = $rating->getTable();

        return new RatingsMorphMany(
            $rating->newQuery(),
            $parent,
            $table.'.'.$side.'_type',
            $table.'.'.$side.'_id',
            $parent->getKeyName()
        );
    }

    /**
     * Limit a ratings query to one rater or rateable. An unsaved model matches nothing.
     */
    public static function constrain(Builder $query, string $side, Model $model): void
    {
        if (! self::isPersisted($model)) {
            $query->whereRaw('0 = 1');

            return;
        }

        [$type, $key] = self::endpoint($model, $side);

        $query->where($query->qualifyColumn($side.'_type'), $type);
        $query->where($query->qualifyColumn($side.'_id'), $key);
    }

    /**
     * The average rating of the rateable, rounded to 4 decimal places, or null when it has none.
     */
    public static function average(Model $rateable): ?float
    {
        $average = self::relation($rateable, 'rateable')->toBase()->avg('value');

        if ($average === null) {
            return null;
        }

        return round(is_numeric($average) ? (float) $average : 0.0, RatingScale::DECIMALS);
    }

    /**
     * The sum of the rateable's ratings: 0 when it has none.
     */
    public static function sum(Model $rateable): int|float
    {
        $sum = RatingScale::fromDatabase(self::relation($rateable, 'rateable')->toBase()->sum('value'));

        return $sum === null ? 0 : RatingScale::toNumber($sum);
    }

    /**
     * How many ratings of each value the rateable received, from one grouped
     * query, by ascending value. Only values that occur are listed.
     *
     * @return array<int|string, int> whole values as int keys, others as decimal strings ('4.5')
     */
    public static function distribution(Model $rateable): array
    {
        $rows = self::relation($rateable, 'rateable')->toBase()
            ->select('value')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('value')
            ->orderBy('value')
            ->get();

        $distribution = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }

            $value = RatingScale::fromDatabase($row->value ?? null);

            if ($value === null) {
                continue;
            }

            $key = str_contains($value, '.') ? $value : (int) $value;
            $count = $row->aggregate ?? 0;
            $distribution[$key] = ($distribution[$key] ?? 0) + (is_numeric($count) ? (int) $count : 0);
        }

        return $distribution;
    }

    /**
     * Only the rateables whose average rating, rounded to 4 decimal places as
     * average() rounds it, is at least the minimum. Rateables without ratings never match.
     *
     * @throws InvalidArgumentException when the minimum is not a number with up to 4 decimal places
     */
    public static function whereAverageAtLeast(Builder $query, Model $rateable, mixed $minimum): void
    {
        $minimum = RatingScale::normalize($minimum, 'minimum average');
        $relation = self::relation($rateable, 'rateable');
        $ratings = $relation->getRelated()->newQueryWithoutRelationships();
        // A wrapped identifier; Laravel 13 types raw SQL as a literal string.
        /** @var literal-string&non-falsy-string $column */
        $column = 'avg('.$ratings->getQuery()->getGrammar()->wrap($ratings->qualifyColumn('value')).')';

        $average = $relation->getRelationExistenceQuery($ratings, $query, [new Expression($column)]);

        // The subquery's SQL from wrapped identifiers; its values and the minimum stay bindings. Laravel 13 types raw SQL as a literal string.
        /** @var literal-string&non-falsy-string $sql */
        $sql = 'round(('.$average->toSql().'), '.RatingScale::DECIMALS.') >= cast(? as decimal(10, '.RatingScale::DECIMALS.'))';

        $query->whereRaw($sql, array_merge($average->getBindings(), [$minimum]));
    }

    /**
     * Delete the ratings of a model that is being deleted, unless it is only soft deleted.
     */
    public static function cleanUp(Model $model, string $side): void
    {
        if (! (bool) Config::get('pharaonic.ratings.cleanup_on_delete', true)) {
            return;
        }

        if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
            return;
        }

        $key = self::key($model);

        if ($key === null) {
            return;
        }

        self::unscoped()->toBase()
            ->where($side.'_type', $model->getMorphClass())
            ->where($side.'_id', $key)
            ->delete();
    }

    /**
     * A comment as the rating stores it: the string exactly as given, or null
     * for null and for a blank string (empty, or only whitespace).
     *
     * @throws InvalidArgumentException when it is not a string or null, not valid UTF-8, or longer than COMMENT_BYTES
     */
    public static function comment(mixed $comment): ?string
    {
        if ($comment === null) {
            return null;
        }

        if (! is_string($comment)) {
            throw new InvalidArgumentException(sprintf(
                'The comment must be a string or null, not %s.', get_debug_type($comment)
            ));
        }

        if (trim($comment) === '') {
            return null;
        }

        if (preg_match('//u', $comment) !== 1) {
            throw new InvalidArgumentException('The comment must be valid UTF-8.');
        }

        if (strlen($comment) > self::COMMENT_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'The comment is %d bytes long; a comment may have up to %d bytes.', strlen($comment), self::COMMENT_BYTES
            ));
        }

        return $comment;
    }

    /**
     * The model a rating rates, for its scale: the one rate() is saving, the
     * loaded rateable relation when it matches the rating's columns, or the
     * row loaded through the morph map, without global scopes, so a soft
     * deleted rateable is found too.
     *
     * @throws UnexpectedValueException when the rating has no rateable, or it does not exist
     */
    public static function rateableOf(Rating $rating): Model
    {
        if (isset(self::$saving[spl_object_id($rating)])) {
            return self::$saving[spl_object_id($rating)];
        }

        $type = $rating->getAttribute('rateable_type');
        $key = self::keyToString($rating->getAttribute('rateable_id'));

        if (! is_string($type) || $type === '' || $key === null) {
            throw new UnexpectedValueException('The rating has no rateable_type and rateable_id, so its rating scale is unknown.');
        }

        if ($rating->relationLoaded('rateable')) {
            $loaded = $rating->getRelation('rateable');

            if ($loaded instanceof Model && $loaded->getMorphClass() === $type && self::key($loaded) === $key) {
                return $loaded;
            }
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (class_exists($class) && is_subclass_of($class, Model::class)) {
            $instance = new $class;
            $found = (new Builder($instance->getConnection()->query()))->setModel($instance)->where($instance->getQualifiedKeyName(), $key)->first();

            if ($found instanceof Model) {
                return $found;
            }
        }

        throw new UnexpectedValueException(sprintf(
            'The rateable [%s] [%s] of the rating does not exist, so its rating scale is unknown. '
            .'Delete the orphaned rating, or point it at an existing model.',
            $type, $key
        ));
    }

    /**
     * Whether the database refused a row because of a unique index.
     */
    public static function isUniqueViolation(QueryException $exception): bool
    {
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $sqlState = isset($errorInfo[0]) && is_string($errorInfo[0]) ? $errorInfo[0] : (string) $exception->getCode();
        $driverCode = $errorInfo[1] ?? null;

        // PostgreSQL: unique_violation.
        if ($sqlState === '23505') {
            return true;
        }

        // MySQL and MariaDB: ER_DUP_ENTRY, ER_DUP_ENTRY_WITH_KEY_NAME.
        // SQL Server: duplicate key in a unique index or constraint.
        if (in_array($driverCode, [1062, 1586, 2601, 2627], true)) {
            return true;
        }

        // SQLite reports every constraint failure as code 19.
        return str_contains($exception->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Whether the database refused a row because of the pair's own unique
     * index, {table}_unique_pair, rather than any other index on the table.
     *
     * MySQL, MariaDB, PostgreSQL and SQL Server name the index in the message;
     * SQLite lists its columns instead.
     */
    public static function isPairViolation(QueryException $exception, Rating $rating): bool
    {
        if (! self::isUniqueViolation($exception)) {
            return false;
        }

        $message = $exception->getMessage();
        $table = $rating->getTable();

        if (str_contains($message, $table.'_unique_pair')) {
            return true;
        }

        $qualified = $rating->getConnection()->getTablePrefix().$table.'.';

        return str_contains($message, 'UNIQUE constraint failed: '.$qualified.'rateable_type, '.$qualified.'rateable_id, '
            .$qualified.'rater_type, '.$qualified.'rater_id');
    }

    /**
     * Run the write in a transaction, and again in a new one when the write
     * ended its own transaction after a refused insert (see write()).
     *
     * @param  array{rater_type: string, rater_id: string, rateable_type: string, rateable_id: string}  $pair
     * @return array{0: 'created'|'updated'|'unchanged', 1: Rating, 2: int|float|null, 3: string|null}
     *
     * @throws RuntimeException when the pair's row keeps vanishing
     */
    private static function writeInTransactions(array $pair, Model $rateable, string $value, bool $commentGiven, ?string $comment): array
    {
        $connection = self::newModel()->getConnection();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            /** @var array{0: 'created'|'updated'|'unchanged', 1: Rating, 2: int|float|null, 3: string|null}|null $result */
            $result = $connection->transaction(function () use ($pair, $rateable, $value, $commentGiven, $comment) {
                return self::write($pair, $rateable, $value, $commentGiven, $comment);
            }, self::ATTEMPTS);

            if ($result !== null) {
                return $result;
            }
        }

        throw self::vanished();
    }

    /**
     * Create, update, or keep the pair's rating, inside the write's transaction.
     *
     * When the pair's unique index refuses the insert and this is the
     * outermost transaction, returns null, and rate() runs the write again in
     * a new transaction. On MySQL and MariaDB, the refused insert leaves a
     * shared lock on the row that won; several refused calls that each then
     * ask for the exclusive lock deadlock one another. Ending the transaction
     * lets go of the shared lock, and the next one locks the row exclusively
     * from its first read. Inside a caller's transaction the lock cannot be
     * let go, so the row is locked there and then.
     *
     * @param  array{rater_type: string, rater_id: string, rateable_type: string, rateable_id: string}  $pair
     * @return array{0: 'created'|'updated'|'unchanged', 1: Rating, 2: int|float|null, 3: string|null}|null
     *                                                                                                       the status, the rating, and its value and comment before the write
     */
    private static function write(array $pair, Model $rateable, string $value, bool $commentGiven, ?string $comment): ?array
    {
        self::lockSqlite();

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            // The first read may come from the transaction's snapshot. After a
            // miss, a locking read returns the row as it is committed now.
            $locked = $attempt > 1;
            $rating = self::find($pair, $locked);

            if ($rating === null) {
                $rating = self::insert($pair, $rateable, $value, $comment);

                if ($rating !== null) {
                    return ['created', $rating, null, null];
                }

                if (self::newModel()->getConnection()->transactionLevel() === 1) {
                    return null;
                }

                $rating = self::find($pair, true);

                // Refused, then deleted before it could be read: try again.
                if ($rating === null) {
                    continue;
                }

                $locked = true;
            }

            // Lock the row before reading what it holds, so the previous value
            // is the committed one this write replaces, and a concurrent delete
            // cannot turn the update into a write that changed nothing.
            if (! $locked) {
                $rating = self::find($pair, true);

                if ($rating === null) {
                    continue;
                }
            }

            $previousValue = $rating->value;
            $previousComment = $rating->comment;

            $rating->setAttribute('value', $value);

            if ($commentGiven) {
                $rating->setAttribute('comment', $comment);
            }

            if (! $rating->isDirty()) {
                return ['unchanged', $rating, $previousValue, $previousComment];
            }

            if (! self::save($rating, $rateable)) {
                throw self::cancelled('updated');
            }

            return [$rating->wasChanged() ? 'updated' : 'unchanged', $rating, $previousValue, $previousComment];
        }

        throw self::vanished();
    }

    private static function vanished(): RuntimeException
    {
        return new RuntimeException(sprintf(
            'The rating was deleted while it was being written, %d times in a row. Try again.', self::ATTEMPTS
        ));
    }

    /**
     * Insert the pair's rating. Null when the pair's unique index refuses it,
     * because another call holds the pair.
     *
     * @param  array{rater_type: string, rater_id: string, rateable_type: string, rateable_id: string}  $pair
     *
     * @throws QueryException when the insert fails for any other reason, such as another unique index
     */
    private static function insert(array $pair, Model $rateable, string $value, ?string $comment): ?Rating
    {
        $rating = self::newModel();
        $rating->forceFill($pair);
        $rating->setAttribute('value', $value);
        $rating->setAttribute('comment', $comment);
        $connection = $rating->getConnection();

        try {
            // Inside a transaction, a savepoint keeps a refused insert from
            // aborting the transaction (PostgreSQL aborts it otherwise).
            $saved = $connection->transactionLevel() > 0
                ? $connection->transaction(function () use ($rating, $rateable) {
                    return self::save($rating, $rateable);
                })
                : self::save($rating, $rateable);
        } catch (QueryException $exception) {
            if (self::isPairViolation($exception, $rating)) {
                return null;
            }

            throw $exception;
        }

        if (! $saved) {
            throw self::cancelled('created');
        }

        return $rating;
    }

    /**
     * On SQLite, take the database's write lock before the write reads.
     *
     * A SQLite transaction that reads first cannot wait for another writer
     * when it then writes: SQLite fails it at once ("database is locked"). An
     * UPDATE that matches no row takes the write lock, waiting for the busy
     * timeout like any write, and changes nothing, so no insert has to be
     * tried, and no model event fired, for a row that may already exist.
     */
    private static function lockSqlite(): void
    {
        $rating = self::newModel();

        if ($rating->getConnection()->getDriverName() !== 'sqlite') {
            return;
        }

        $query = self::unscoped($rating)->toBase();

        // A wrapped identifier; Laravel 13 types raw SQL as a literal string.
        /** @var literal-string&non-falsy-string $key */
        $key = $query->getGrammar()->wrap($rating->getKeyName());

        $query->whereRaw('0 = 1')->update([$rating->getKeyName() => new Expression($key)]);
    }

    /**
     * Save the rating, telling its scale check which model it rates.
     */
    private static function save(Rating $rating, Model $rateable): bool
    {
        self::$saving[spl_object_id($rating)] = $rateable;

        try {
            return $rating->save();
        } finally {
            unset(self::$saving[spl_object_id($rating)]);
        }
    }

    /**
     * Delete the rating's row. True when this call deleted it.
     *
     * A query-builder delete, so Eloquent's deleting and deleted model events do not run.
     */
    private static function delete(Rating $rating): bool
    {
        $deleted = self::unscoped($rating)->toBase()
            ->where($rating->getKeyName(), $rating->getKey())
            ->delete() > 0;

        if ($deleted) {
            $rating->exists = false;
        }

        return $deleted;
    }

    /**
     * The pair's rating, read from the write connection so a lagging replica
     * cannot hide a row the statement before just wrote or kept.
     *
     * With $lock, inside a transaction, the read locks the row for update: on
     * MySQL (REPEATABLE READ) a plain read returns the transaction's snapshot,
     * which misses a row another request committed since; a locking read returns
     * it, and keeps it from being changed or deleted before this transaction ends.
     *
     * @param  array{rater_type: string, rater_id: string, rateable_type: string, rateable_id: string}  $pair
     */
    private static function find(array $pair, bool $lock = false): ?Rating
    {
        $query = self::unscoped()->where($pair);
        $query->getQuery()->useWritePdo();

        if ($lock && $query->getQuery()->getConnection()->transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $rating = $query->first();

        return $rating instanceof Rating ? $rating : null;
    }

    /**
     * A query on the ratings table without the model's global scopes, which
     * must not hide the row that holds a pair.
     */
    private static function unscoped(?Rating $rating = null): Builder
    {
        $rating = $rating ?? self::newModel();

        return (new Builder($rating->getConnection()->query()))->setModel($rating);
    }

    private static function cancelled(string $action): RuntimeException
    {
        return new RuntimeException(sprintf(
            'A model event cancelled saving the rating, so it was not %s.', $action
        ));
    }

    /**
     * The four columns that identify the rating of a pair.
     *
     * @return array{rater_type: string, rater_id: string, rateable_type: string, rateable_id: string}
     */
    private static function pair(Model $rater, Model $rateable): array
    {
        [$raterType, $raterId] = self::endpoint($rater, 'rater');
        [$rateableType, $rateableId] = self::endpoint($rateable, 'rateable');

        return [
            'rater_type' => $raterType,
            'rater_id' => $raterId,
            'rateable_type' => $rateableType,
            'rateable_id' => $rateableId,
        ];
    }

    /**
     * The morph type and key the ratings table stores for the model.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException when the model is unsaved, or its type or key does not fit
     */
    private static function endpoint(Model $model, string $side): array
    {
        if (! $model->exists) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] is not saved: save it before it rates or is rated.', $side, get_class($model)
            ));
        }

        $key = self::key($model);

        if ($key === null) {
            throw new InvalidArgumentException(sprintf(
                'The %s [%s] has no usable primary key.', $side, get_class($model)
            ));
        }

        if (mb_strlen($key) > self::KEY_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The key of the %s [%s] is %d characters long; the ratings table stores keys of up to %d characters.',
                $side, get_class($model), mb_strlen($key), self::KEY_LENGTH
            ));
        }

        $type = $model->getMorphClass();

        if (mb_strlen($type) > self::TYPE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'The morph type of the %s [%s] is %d characters long; the ratings table stores types of up to %d characters. '
                .'Register a shorter alias with Relation::morphMap().',
                $side, get_class($model), mb_strlen($type), self::TYPE_LENGTH
            ));
        }

        return [$type, $key];
    }

    /**
     * Whether the model is saved and has a usable key.
     */
    private static function isPersisted(Model $model): bool
    {
        return $model->exists && self::key($model) !== null;
    }

    /**
     * The model key as the string the ratings table stores, or null when it has none.
     */
    private static function key(Model $model): ?string
    {
        return self::keyToString($model->getKey());
    }

    /**
     * A key as the string the ratings table stores, or null when it is not a usable key.
     */
    public static function keyToString(mixed $key): ?string
    {
        if (! is_int($key) && ! is_string($key) && ! $key instanceof Stringable) {
            return null;
        }

        $key = (string) $key;

        return $key === '' ? null : $key;
    }
}
