<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

/**
 * The aggregates of one rateable and the lookups of one pair or one rater
 * read through the table's indexes, not a full scan.
 */
class QueryPlanTest extends TestCase
{
    public function test_the_aggregates_of_a_rateable_use_the_pair_index(): void
    {
        $product = $this->seedRatings();

        $relation = $product->ratings();
        $query = $relation->toBase()->selectRaw('avg(value) as aggregate');

        $this->assertUsesIndex($query->toSql(), $query->getBindings(), 'ratings_unique_pair');
    }

    public function test_a_pair_lookup_uses_the_pair_index(): void
    {
        $product = $this->seedRatings();
        $user = User::query()->firstOrFail();

        $query = $user->givenRatings()->toBase()->where('rateable_type', Product::class)->where('rateable_id', (string) $product->getKey());

        $this->assertUsesIndex($query->toSql(), $query->getBindings(), 'ratings_unique_pair');
    }

    public function test_the_ratings_a_rater_gave_use_the_rater_index(): void
    {
        $this->seedRatings();
        $bot = Bot::query()->firstOrFail();

        $query = $bot->givenRatings()->toBase();

        $this->assertUsesIndex($query->toSql(), $query->getBindings(), 'ratings_rater_index');
    }

    private function seedRatings(): Product
    {
        $products = [];

        for ($i = 0; $i < 20; $i++) {
            $products[] = Product::create();
        }

        $bot = Bot::create();

        for ($i = 0; $i < 10; $i++) {
            $user = User::create();

            foreach ($products as $product) {
                $user->rate($product, ($i % 5) + 1);
            }
        }

        $bot->rate($products[0], 3);

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('analyze table ratings');
        } elseif (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('analyze ratings');
            // A small table is cheaper to scan, so PostgreSQL would choose that.
            DB::statement('set enable_seqscan = off');
        }

        return $products[0];
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function assertUsesIndex(string $sql, array $bindings, string $index): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $plan = json_encode(DB::select('explain query plan '.$sql, $bindings));
        } elseif ($driver === 'pgsql') {
            $plan = json_encode(DB::select('explain '.$sql, $bindings));
            DB::statement('set enable_seqscan = on');
        } else {
            $plan = json_encode(DB::select('explain '.$sql, $bindings));
        }

        $this->assertIsString($plan);
        $this->assertStringContainsString($index, $plan, $plan);
    }
}
