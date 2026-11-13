# Maintaining This Package

This file records package-specific maintainer facts that are safe to keep in the public repository.

## Purpose

Polymorphic ratings between any two Eloquent models: `CanRate` for the model that rates, `HasRatings` for the model that is rated, one `ratings` table whose unique index keeps each pair to one rating, with a value on a scale and an optional comment.

## Supported Laravel lines

One branch per Laravel major, from `6.x` to the newest major. Each line's PHP range, `illuminate/*` constraint, and tools follow the Pharaonic Laravel line table. `6.x` runs PHPUnit; later lines run Pest.

## Core library

None. The scale arithmetic is a few exact integer operations, and everything else is Eloquent glue.

## Public API invariants

Configuration keys (`pharaonic.ratings.*`), publish tags, and registered Blade, Artisan, macro, and validation names are public API.

- Configuration: `pharaonic.ratings.table` (default `ratings`), `pharaonic.ratings.model` (default `Models\Rating`, or a subclass), `pharaonic.ratings.min`, `max`, `step` (default 1, 5, 1), and `pharaonic.ratings.cleanup_on_delete` (default `true`).
- The `model` setting is supported and tested on every path (writes, reads, relations, scopes, aggregates, cleanup, events, migration table and connection), but deliberately not documented in the README or `docs/`. A subclass's own `$table` wins over the `table` setting; its `$connection` is where the migration creates the table.
- Publish tags: `pharaonic`, `laravel-ratings`, `pharaonic-config`, `ratings-config`, `pharaonic-migrations`, and `ratings-migrations`.
- `CanRate`: `givenRatings()`, `rate()`, `ratingFor()`, `hasRated()`, `unrate()`. `HasRatings`: `ratings()`, `ratingScale()`, `rateBy()`, `unrateBy()`, `ratedBy()`, `ratingFrom()`, `averageRating()`, `ratingsCount()`, `ratingsSum()`, `ratingDistribution()`, `ratingsWithComments()`, `scopeWhereRatedBy()`, `scopeWhereRatingAtLeast()`. Neither trait defines a method of the other, so a model can use both.
- `rate()` and `rateBy()` return `Support\RatingResult`: `created()`, `updated()`, `unchanged()`, `changed()`, `status()` (`created`, `updated`, `unchanged`), `previous()`, `current()`, `rating()`. It is final, with private state and no mutators. A cancelled save or a removal is never a result: the first throws, the second is `unrate()`'s boolean.
- Comment semantics: whether the comment argument was passed is read with `func_num_args()` in the trait methods, never from its default. Omitted keeps the stored comment (a new rating has none); a string sets it; `null` clears it. A blank string (PHP `trim()` leaves nothing) is stored as `null`, everywhere, including the model mutator. Other strings are stored exactly, untrimmed. Comments must be strings, valid UTF-8, and at most 65,535 bytes (MySQL `TEXT`).
- Values are ints, finite floats, or plain decimal strings, with up to 4 decimal places and 6 whole digits (`DECIMAL(10,4)`). They come back as an `int` when whole and a `float` otherwise. The same value in another form (`4`, `4.0`, `'4.0000'`) is unchanged: no UPDATE, no event.
- Scales: `min`, `max`, `step`, with `step > 0`, `max > min`, and `max - min` a whole number of steps. A value is valid when it is `min + n * step` up to `max`. `HasRatings::ratingScale()` returns the configured scale; a model overrides it. A model without the method uses the configured scale.
- `averageRating()` is a float rounded half up to 4 decimal places, or `null`; `ratingsSum()` is `0` without ratings; `ratingDistribution()` lists only the values that occur, ascending, with int keys for whole values and decimal-string keys otherwise (PHP has no float keys); `whereRatingAtLeast()` compares the average rounded the same way, so it agrees with `averageRating()`.
- `Models\Rating` with `rater()`, `rateable()`, `scopeWhereRater()`, `scopeWhereRateable()`, the `value` accessor and mutator, the `comment` mutator, `$fillable = ['value', 'comment']`, and the columns `rater_type`, `rater_id`, `rateable_type`, `rateable_id`, `value`, `comment`.
- `Events\RatingCreated`, `Events\RatingUpdated` (also `$previousValue`, `$previousComment`), and `Events\RatingRemoved`, each with public `$rating`, `$rater`, and `$rateable`, dispatched only when the call inserted, changed, or deleted the row.
- Errors are SPL exceptions: `InvalidArgumentException` for input (unsaved model, value, comment, minimum average), `UnexpectedValueException` for configuration (scale, `model` setting) and for stored data that cannot be used (an orphaned rateable), `RuntimeException` when a model event cancels the save or a pair keeps vanishing under the write.
- `Support\RatingManager`, `Support\RatingScale`, and `Relations\RatingsMorphMany` are internal.

## Package-specific constraints

- The migration file name `2026_11_13_000001_create_pharaonic_ratings_table.php` and its class `CreatePharaonicRatingsTable` never change. The class is named because `6.x` supports Laravel below 8.37.
- Later schema changes go in new migrations, never in the released one.
- The migration refuses to run over an existing table, and creates the table with the configured model's table name on the configured model's connection.
- The unique index `{table}_unique_pair` is `(rateable_type, rateable_id, rater_type, rater_id)`: 191 + 64 + 191 + 64 characters, 2,040 bytes in `utf8mb4`, under InnoDB's 3,072-byte key limit, with no column over the 767-byte prefix limit of older row formats. Verified by running the suite on MySQL 5.7, MySQL 8.4, and MariaDB 10.6. It also serves every query of one rateable, aggregates included. `{table}_rater_index` serves the ratings of one rater. `QueryPlanTest` checks both on SQLite, MySQL, and PostgreSQL.
- `value` is an accessor and mutator, not a cast class: custom casts need Laravel 7. The mutator keeps the stored string when the new value is equal (`'4.5000'` for `4.5`), so the attribute stays clean.
- The value is checked against the scale in the model's `saving` event, only when it, `rateable_type`, or `rateable_id` is new or dirty, so a rating from before a scale change can still have its comment edited. `rate()` checks it before the transaction too, and hands the rateable it has to the `saving` check (`RatingManager::$saving`), so the check loads nothing. A direct save loads the rateable through the morph map without global scopes (soft-deleted rateables are found), and does not keep it as a relation, so the rating's array and JSON do not change. An orphaned rateable is an `UnexpectedValueException`, never the configured scale.
- Query-builder updates (`Rating::query()->update()`) and raw SQL skip the model, so neither the mutators nor the scale check run.
- Every write runs in a transaction on the model's connection, through `Connection::transaction($callback, 3)`: outside a caller's transaction Laravel retries deadlocks and SQLite "database is locked"; inside one, the error goes to the caller. The insert runs in a savepoint, so a refused insert leaves PostgreSQL's transaction usable.
- Uniqueness comes from the `{table}_unique_pair` index. Its violation is told apart from any other unique index by name (MySQL, MariaDB, PostgreSQL, SQL Server) or by its four columns (SQLite); any other violation is thrown at once. After the pair's violation, a write that owns the outermost transaction commits it empty and writes again in a new one (up to 3 times): on MySQL and MariaDB the refused insert leaves a shared lock on the winning row, and several refused calls that then ask for the exclusive lock deadlock one another (seen on MariaDB 10.6 with 8 simultaneous first ratings: 2 failures in 5 runs before, none in 10 after). Inside a caller's transaction the lock cannot be let go, so the row is read with `lockForUpdate()` there and then, and a deadlock goes to the caller. `laravel-bookmarks` and the other siblings still read with the lock in their own transaction, and may hit the same deadlock under heavy contention.
- The write reads first. An existing row is read again with `lockForUpdate()` before its value and comment are compared, so `previous()` and `RatingUpdated::$previousValue` are the committed state this write replaced: concurrent writes form one chain, the last committed write wins, and no update is lost. All reads use the write PDO.
- On SQLite the write first runs `UPDATE {table} SET id = id WHERE 0 = 1`, which takes the database's write lock (waiting for the busy timeout) and changes nothing: a SQLite transaction that reads first fails at once instead of waiting when it then writes. This replaces the insert-first approach of `laravel-bookmarks`, which fires `saving`/`creating` model events for an insert the unique index then refuses.
- Removal is a query-builder delete checked by affected rows, so Eloquent `deleting`/`deleted` events do not run for it. Cleanup on delete is a bulk delete and dispatches no `RatingRemoved`.
- Events are dispatched right after the write's own transaction, so inside a caller's transaction they come before it commits, because `ShouldDispatchAfterCommit` needs Laravel 10.30. Retries happen inside the transaction, before any dispatch, so a retried write dispatches once.
- Ids are stored as strings (`varchar(64)`), so the rating relations compare the parent key as a string in `has()`, `whereHas()`, `withCount()`, and `whereRatingAtLeast()` (`RatingsMorphMany`): PostgreSQL refuses `bigint = varchar` and `uuid = varchar`. MySQL uses `CONCAT()` rather than `CAST()`, which fails when the connection collation differs from the table's. A model on another connection cannot be used in `whereHas()`-style subqueries across databases.
- `whereRatingAtLeast()` compares `round((avg subquery), 4) >= cast(? as decimal(10, 4))`: the cast keeps SQLite from comparing the bound string as text.
- The scale arithmetic counts 0.0001 units in PHP integers, so it needs 64-bit PHP.
- `phpstan.neon.dist` also analyses `tests/Fixtures`: PHPStan analyses a trait only through a class that uses it.
- `ConcurrencyTest` runs separate PHP processes against a shared database: a SQLite file by default, MySQL, MariaDB, or PostgreSQL through the `RATINGS_DB*` environment variables (`.github/workflows/databases.yml`).

## Branch notes

Fixes land on the oldest affected line and are ported to every later line and `develop`.

## Documentation

https://pharaonic.dev/packages/laravel/ratings

Keep this file factual and package-specific. General Pharaonic engineering policy belongs in the private engineering repository, not here.
