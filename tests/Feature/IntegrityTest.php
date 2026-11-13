<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Pharaonic\Laravel\Ratings\Events\RatingCreated;
use Pharaonic\Laravel\Ratings\Events\RatingUpdated;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingManager;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;
use RuntimeException;
use stdClass;

class IntegrityTest extends TestCase
{
    public function test_a_rating_another_connection_wrote_is_updated_with_its_previous_value(): void
    {
        $this->useSharedDatabase();
        $user = User::create();
        $product = Product::create();
        $this->insertFromAnotherConnection($user, $product, '2', 'From the other request');

        Event::fake([RatingCreated::class, RatingUpdated::class]);

        $result = $user->rate($product, 4);

        $this->assertTrue($result->updated());
        $this->assertSame(2, $result->previous());
        $this->assertSame(4, $result->current());
        $this->assertSame('From the other request', $result->rating()->comment);
        $this->assertSame(1, Rating::query()->count());
        Event::assertNotDispatched(RatingCreated::class);
        Event::assertDispatchedTimes(RatingUpdated::class, 1);
    }

    public function test_a_unique_violation_on_insert_updates_the_winning_row_and_keeps_its_comment(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite lets one connection write at a time.');
        }

        $user = User::create();
        $product = Product::create();
        $attempts = new stdClass;
        $attempts->count = 0;

        // Another request commits the pair between this call's read and its insert.
        Rating::creating(function () use ($attempts, $user, $product) {
            if ($attempts->count++ === 0) {
                $this->insertFromAnotherConnection($user, $product, '5', 'Winner');
            }
        });
        Event::fake([RatingCreated::class, RatingUpdated::class]);

        $result = $user->rate($product, 3);

        $this->assertSame(1, $attempts->count);
        $this->assertTrue($result->updated());
        $this->assertSame(5, $result->previous());
        $this->assertSame(3, $result->current());
        $this->assertSame('Winner', $user->ratingFor($product)?->comment);
        $this->assertSame(3, $user->ratingFor($product)?->value);
        $this->assertSame(1, Rating::query()->count());
        Event::assertNotDispatched(RatingCreated::class);
        Event::assertDispatchedTimes(RatingUpdated::class, 1);
    }

    public function test_a_lost_insert_with_the_same_value_is_unchanged(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite lets one connection write at a time.');
        }

        $user = User::create();
        $product = Product::create();
        $inserted = false;

        Rating::creating(function () use (&$inserted, $user, $product) {
            if (! $inserted) {
                $inserted = true;
                $this->insertFromAnotherConnection($user, $product, '3', null);
            }
        });
        Event::fake([RatingCreated::class, RatingUpdated::class]);

        $result = $user->rate($product, 3);

        $this->assertTrue($result->unchanged());
        $this->assertSame(3, $result->previous());
        Event::assertNotDispatched(RatingCreated::class);
        Event::assertNotDispatched(RatingUpdated::class);
    }

    public function test_a_rating_committed_during_the_callers_transaction_is_found_not_thrown(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite lets one connection write at a time.');
        }

        $user = User::create();
        $product = Product::create();

        DB::transaction(function () use ($user, $product) {
            // MySQL (REPEATABLE READ) takes the transaction's snapshot at its first read.
            $this->assertFalse($user->hasRated($product));

            $this->insertFromAnotherConnection($user, $product, '2', 'Other');

            $result = $user->rate($product, 4);
            $this->assertTrue($result->updated());
            $this->assertSame(2, $result->previous());
            $this->assertSame('Other', $result->rating()->comment);
        });

        $this->assertSame(4, $user->ratingFor($product)?->value);
        $this->assertSame(1, Rating::query()->count());
    }

    public function test_the_write_reads_the_row_back_from_the_write_connection(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The replica is simulated with a second SQLite file.');
        }

        $this->useSharedDatabase();
        $replica = tempnam(sys_get_temp_dir(), 'laravel-ratings-replica-');
        $this->assertIsString($replica);
        $this->beforeApplicationDestroyed(function () use ($replica) {
            @unlink($replica);
        });

        $user = User::create();
        $product = Product::create();
        $user->rate($product, 2, 'Primary');

        // Reads go to an empty replica that has not caught up; writes go to the primary.
        $config = $this->app['config']->get('database.connections.testing');
        $this->app['config']->set('database.connections.testing', array_merge($config, [
            'read' => ['database' => $replica],
            'write' => ['database' => $config['database']],
        ]));
        DB::purge('testing');

        $result = $user->rate($product, 5);

        $this->assertTrue($result->updated());
        $this->assertSame(2, $result->previous());
        $this->assertSame('Primary', $result->rating()->comment);
        $this->assertSame(1, DB::connection()->table('ratings')->useWritePdo()->count());
    }

    public function test_a_violation_of_another_unique_index_is_not_swallowed(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->unique(['rater_type', 'rater_id'], 'ratings_one_per_rater');
        });

        $user = User::create();
        $user->rate(Product::create(), 4);

        try {
            $user->rate(Product::create(), 4);
            $this->fail('Another unique index violation was swallowed.');
        } catch (QueryException $exception) {
            $this->assertTrue(RatingManager::isUniqueViolation($exception));
        }

        $this->assertSame(1, Rating::query()->count());
    }

    public function test_other_database_errors_are_not_swallowed(): void
    {
        $user = User::create();
        $product = Product::create();
        Schema::drop('ratings');

        $calls = [
            'rate' => function () use ($user, $product) {
                $user->rate($product, 4);
            },
            'unrate' => function () use ($user, $product) {
                $user->unrate($product);
            },
            'hasRated' => function () use ($user, $product) {
                $user->hasRated($product);
            },
            'ratingFor' => function () use ($user, $product) {
                $user->ratingFor($product);
            },
            'averageRating' => function () use ($product) {
                $product->averageRating();
            },
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail("{$method}() hid a missing table.");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('ratings', $exception->getMessage());
            }
        }
    }

    public function test_an_existing_pair_leaves_the_callers_transaction_usable(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 2);

        DB::transaction(function () use ($user, $product) {
            $this->assertTrue($user->rate($product, 3)->updated());
            $this->assertTrue($user->unrate($product));
            $this->assertTrue($user->rate($product, 4, 'Again')->created());
            $this->assertSame(1, Product::query()->count());
        });

        $this->assertSame(4, $user->ratingFor($product)?->value);
        $this->assertSame('Again', $user->ratingFor($product)?->comment);
    }

    public function test_a_real_duplicate_key_inside_the_callers_transaction_keeps_it_usable(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite lets one connection write at a time.');
        }

        $user = User::create();
        $product = Product::create();
        $inserted = false;

        // The database itself refuses this call's insert: PostgreSQL aborts the
        // whole transaction on any error unless the insert ran in a savepoint.
        Rating::creating(function () use (&$inserted, $user, $product) {
            if (! $inserted) {
                $inserted = true;
                $this->insertFromAnotherConnection($user, $product, '2', 'Other');
            }
        });

        DB::transaction(function () use ($user, $product) {
            $result = $user->rate($product, 5);

            $this->assertTrue($result->updated());
            $this->assertSame(2, $result->previous());
            // The transaction still runs queries.
            $this->assertSame(1, Product::query()->count());
            $this->assertTrue($user->rate(Product::create(), 1)->created());
        });

        $this->assertTrue($inserted);
        $this->assertSame(5, $user->ratingFor($product)?->value);
        $this->assertSame(2, Rating::query()->count());
    }

    public function test_a_rolled_back_transaction_rolls_the_rating_back(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 2, 'Before');

        DB::beginTransaction();
        $user->rate($product, 5, 'Inside');
        $user->rate(Product::create(), 3);
        DB::rollBack();

        $this->assertSame(2, $user->ratingFor($product)?->value);
        $this->assertSame('Before', $user->ratingFor($product)?->comment);
        $this->assertSame(1, Rating::query()->count());
    }

    public function test_a_deadlock_outside_a_transaction_is_retried_with_one_event(): void
    {
        $attempts = $this->deadlockOnCreating(1);
        Event::fake([RatingCreated::class]);

        $result = User::create()->rate(Product::create(), 4);

        $this->assertTrue($result->created());
        $this->assertSame(2, $attempts->count);
        $this->assertSame(1, Rating::query()->count());
        Event::assertDispatchedTimes(RatingCreated::class, 1);
    }

    public function test_a_deadlock_that_keeps_happening_is_thrown(): void
    {
        $attempts = $this->deadlockOnCreating(PHP_INT_MAX);
        Event::fake([RatingCreated::class]);
        $user = User::create();
        $product = Product::create();

        try {
            $user->rate($product, 4);
            $this->fail('The deadlock was swallowed.');
        } catch (PDOException $exception) {
            // Laravel 8.x+ rethrows it as DeadlockException.
            $this->assertStringContainsString('Deadlock found', $exception->getMessage());
        }

        $this->assertSame(3, $attempts->count);
        $this->assertSame(0, Rating::query()->count());
        $this->assertSame(0, DB::transactionLevel());
        Event::assertNotDispatched(RatingCreated::class);
    }

    public function test_a_deadlock_inside_a_transaction_is_left_to_the_caller(): void
    {
        $attempts = $this->deadlockOnCreating(1);
        $user = User::create();
        $product = Product::create();

        DB::beginTransaction();

        try {
            $user->rate($product, 4);
            $this->fail('The deadlock was retried inside the caller\'s transaction.');
        } catch (PDOException $exception) {
            $this->assertStringContainsString('Deadlock found', $exception->getMessage());
        } finally {
            DB::rollBack();
        }

        $this->assertSame(1, $attempts->count);
    }

    public function test_a_pair_that_keeps_vanishing_after_a_unique_violation_is_thrown_not_looped(): void
    {
        $attempts = $this->violateOnCreating(
            'UNIQUE constraint failed: ratings.rateable_type, ratings.rateable_id, ratings.rater_type, ratings.rater_id'
        );
        Event::fake([RatingCreated::class, RatingUpdated::class]);

        try {
            User::create()->rate(Product::create(), 4);
            $this->fail('A pair that kept vanishing was reported as rated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('deleted while it was being written, 3 times in a row', $exception->getMessage());
        }

        $this->assertSame(3, $attempts->count);
        $this->assertSame(0, Rating::query()->count());
        Event::assertNotDispatched(RatingCreated::class);
        Event::assertNotDispatched(RatingUpdated::class);
    }

    public function test_another_unique_violation_is_thrown_at_once(): void
    {
        $attempts = $this->violateOnCreating('duplicate key value violates unique constraint "ratings_other_unique"', '23505');

        try {
            User::create()->rate(Product::create(), 4);
            $this->fail('Another unique index violation was swallowed.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('ratings_other_unique', $exception->getMessage());
        }

        $this->assertSame(1, $attempts->count);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: bool}>
     */
    public function pairViolations(): array
    {
        return [
            'mysql pair' => ['23000', "Duplicate entry 'x' for key 'ratings.ratings_unique_pair'", 1062, true],
            'mysql other' => ['23000', "Duplicate entry 'x' for key 'ratings.ratings_slug_unique'", 1062, false],
            'pgsql pair' => ['23505', 'duplicate key value violates unique constraint "ratings_unique_pair"', 7, true],
            'pgsql other' => ['23505', 'duplicate key value violates unique constraint "ratings_slug_unique"', 7, false],
            'sqlite pair' => ['23000', 'UNIQUE constraint failed: ratings.rateable_type, ratings.rateable_id, ratings.rater_type, ratings.rater_id', 19, true],
            'sqlite other' => ['23000', 'UNIQUE constraint failed: ratings.slug', 19, false],
            'not unique' => ['23000', 'NOT NULL constraint failed: ratings.value', 19, false],
        ];
    }

    /**
     * @dataProvider pairViolations
     */
    public function test_only_the_pair_index_is_recognized(string $sqlState, string $message, int $code, bool $pair): void
    {
        $pdo = new PDOException('SQLSTATE['.$sqlState.']: '.$message);
        $pdo->errorInfo = [$sqlState, $code, $message];

        $this->assertSame($pair, RatingManager::isPairViolation(new QueryException('insert ...', [], $pdo), new Rating));
    }

    /**
     * Make every insert of a rating fail with the unique violation.
     */
    private function violateOnCreating(string $message, string $sqlState = '23000'): stdClass
    {
        $attempts = new stdClass;
        $attempts->count = 0;

        Rating::creating(function () use ($attempts, $message, $sqlState) {
            $attempts->count++;
            $pdo = new PDOException('SQLSTATE['.$sqlState.']: Integrity constraint violation: '.$message);
            $pdo->errorInfo = [$sqlState, $sqlState === '23505' ? 7 : 19, $message];

            throw new QueryException('insert into "ratings" ...', [], $pdo);
        });

        return $attempts;
    }

    private function insertFromAnotherConnection(User $user, Product $product, string $value, ?string $comment): void
    {
        // A second, independent connection to the same database, as another request would have.
        $this->app['config']->set('database.connections.other', $this->app['config']->get('database.connections.testing'));
        DB::connection('other')->table('ratings')->insert([
            'rater_type' => User::class,
            'rater_id' => (string) $user->getKey(),
            'rateable_type' => Product::class,
            'rateable_id' => (string) $product->getKey(),
            'value' => $value,
            'comment' => $comment,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
        DB::disconnect('other');
    }

    /**
     * Make the first $times inserts of a rating fail as MySQL's deadlock victim.
     */
    private function deadlockOnCreating(int $times): stdClass
    {
        $attempts = new stdClass;
        $attempts->count = 0;

        Rating::creating(function () use ($attempts, $times) {
            if (++$attempts->count > $times) {
                return;
            }

            $message = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction';
            $pdo = new PDOException($message);
            $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

            throw new QueryException('insert into `ratings` ...', [], $pdo);
        });

        return $attempts;
    }
}
