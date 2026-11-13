<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Separate PHP processes, each with its own connection, act on one pair at
 * the same moment. On SQLite the database file serializes the writes; on
 * MySQL and PostgreSQL (RATINGS_DB) they really run in parallel.
 */
class ConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    public function test_simultaneous_first_ratings_create_one_row(): void
    {
        [$user, $product, $results] = $this->race(['rate']);

        $this->assertSame(1, Rating::query()->count());
        $this->assertSame(1, array_sum(array_column($results, 'created')));
        $this->assertSame(1, count(array_filter($results, function (array $result) {
            return $result['status'] === 'created';
        })));
        $this->assertCount(1, array_unique(array_column($results, 'id')));

        // Each call returned what it wrote.
        foreach ($results as $number => $result) {
            $this->assertSame('worker '.($number + 1), $result['comment']);
            $this->assertSame((($number + 1) % 5) + 1, $result['current']);
        }

        $this->assertLastWriteWins($user, $product, $results);
    }

    public function test_simultaneous_updates_form_one_chain_with_no_lost_update(): void
    {
        [$user, $product, $results] = $this->race(['rate'], 'Before');

        $this->assertSame(1, Rating::query()->count());
        $this->assertSame(0, array_sum(array_column($results, 'created')));

        // Every comment differs, so every call updated the row.
        $this->assertSame(self::WORKERS, array_sum(array_column($results, 'updated')));

        $this->assertLastWriteWins($user, $product, $results);
    }

    public function test_simultaneous_value_only_ratings_keep_the_comment(): void
    {
        [$user, $product, $results] = $this->race(['rate-value'], 'Keep me');

        $this->assertSame(1, Rating::query()->count());
        $this->assertSame('Keep me', $user->ratingFor($product)?->comment);

        foreach ($results as $result) {
            $this->assertSame('Keep me', $result['comment']);
            $this->assertContains($result['status'], ['updated', 'unchanged']);
        }
    }

    public function test_simultaneous_removals_delete_the_row_once(): void
    {
        [$user, $product, $results] = $this->race(['unrate'], 'Before');

        $this->assertSame(1, array_sum(array_column($results, 'removed')));
        $this->assertSame(1, count(array_filter(array_column($results, 'removed'))));
        $this->assertSame(0, Rating::query()->count());
        $this->assertFalse($user->hasRated($product));
    }

    public function test_simultaneous_ratings_and_removals_leave_a_valid_state(): void
    {
        [$user, $product, $results] = $this->race(['rate', 'unrate'], 'Before');

        $this->assertLessThanOrEqual(1, Rating::query()->count());

        $stored = $user->ratingFor($product);

        foreach ($results as $result) {
            if (isset($result['status'])) {
                $this->assertContains($result['status'], ['created', 'updated']);
            }
        }

        if ($stored !== null) {
            // The row that survived is one a rate() call reported writing.
            $this->assertContains($stored->comment, array_column($results, 'comment'));
        }
    }

    /**
     * The calls form one chain of committed writes: each update replaced the
     * state another call (or the seeded rating) left, no state was replaced
     * twice, and the row holds the state no later call replaced.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    private function assertLastWriteWins(User $user, Product $product, array $results): void
    {
        $replaced = [];

        foreach ($results as $result) {
            foreach ($result['previousComments'] as $comment) {
                $replaced[] = $comment;
            }
        }

        $this->assertSame(count($replaced), count(array_unique($replaced)), 'A state was replaced twice: an update was lost.');

        $written = array_column($results, 'comment');
        $survivors = array_values(array_diff($written, $replaced));

        $this->assertCount(1, $survivors);
        $this->assertSame($survivors[0], $user->ratingFor($product)?->comment);
    }

    /**
     * @param  array<int, string>  $actions  given to the workers in turn
     * @return array{0: User, 1: Product, 2: array<int, array<string, mixed>>}
     */
    private function race(array $actions, ?string $seed = null): array
    {
        $env = $this->useSharedDatabase();
        $user = User::create();
        $product = Product::create();

        if ($seed !== null) {
            $user->rate($product, 3, $seed);
        }

        // Leave the workers time to boot, then let them all start together.
        $start = (string) (microtime(true) + 3);
        $processes = [];

        for ($i = 1; $i <= self::WORKERS; $i++) {
            $process = new Process(
                [PHP_BINARY, __DIR__.'/../Concurrency/worker.php', $actions[($i - 1) % count($actions)], (string) $user->getKey(), (string) $product->getKey(), $start, (string) $i],
                null,
                $env,
                null,
                60
            );
            $process->start();
            $processes[] = $process;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true);
            $this->assertIsArray($result, $process->getOutput());
            $results[] = $result;
        }

        return [$user, $product, $results];
    }
}
