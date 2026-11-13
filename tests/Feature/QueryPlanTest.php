<?php

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

/*
 * The aggregates of one rateable and the lookups of one pair or one rater
 * read through the table's indexes, not a full scan.
 */

test('the aggregates of a rateable use the pair index', function () {
    $product = seedRatingsForPlans();

    $relation = $product->ratings();
    $query = $relation->toBase()->selectRaw('avg(value) as aggregate');

    assertQueryUsesIndex($query->toSql(), $query->getBindings(), 'ratings_unique_pair');
});

test('a pair lookup uses the pair index', function () {
    $product = seedRatingsForPlans();
    $user = User::query()->firstOrFail();

    $query = $user->givenRatings()->toBase()->where('rateable_type', Product::class)->where('rateable_id', (string) $product->getKey());

    assertQueryUsesIndex($query->toSql(), $query->getBindings(), 'ratings_unique_pair');
});

test('the ratings a rater gave use the rater index', function () {
    seedRatingsForPlans();
    $bot = Bot::query()->firstOrFail();

    $query = $bot->givenRatings()->toBase();

    assertQueryUsesIndex($query->toSql(), $query->getBindings(), 'ratings_rater_index');
});

function seedRatingsForPlans(): Product
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
function assertQueryUsesIndex(string $sql, array $bindings, string $index): void
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

    test()->assertIsString($plan);
    test()->assertStringContainsString($index, $plan, $plan);
}
