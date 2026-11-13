<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Course;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

/**
 * The snippets of docs/ that are more than one call, run as written.
 */
class DocsExamplesTest extends TestCase
{
    public function test_the_orphan_cleanup_deletes_only_orphaned_ratings(): void
    {
        $kept = Product::create();
        $gone = Product::create();
        User::create()->rate($kept, 4);
        User::create()->rate($gone, 2);
        User::create()->rate(Course::create(), 5);
        Product::query()->whereKey($gone->getKey())->delete();

        Rating::query()
            ->where('rateable_type', (new Product)->getMorphClass())
            ->chunkById(500, function ($ratings) {
                $existing = Product::query()
                    ->whereIn('id', $ratings->pluck('rateable_id')->all())
                    ->pluck('id')
                    ->map(fn ($id) => (string) $id)
                    ->all();

                $orphans = $ratings->reject(fn ($rating) => in_array($rating->rateable_id, $existing, true));

                Rating::query()->whereKey($orphans->modelKeys())->delete();
            });

        $this->assertSame(2, Rating::query()->count());
        $this->assertSame(1, $kept->ratingsCount());
    }

    public function test_the_distribution_merged_with_the_scale_lists_every_star(): void
    {
        $product = Product::create();
        User::create()->rate($product, 5);
        User::create()->rate($product, 5);
        User::create()->rate($product, 2);

        $counts = $product->ratingDistribution();
        $scale = $product->ratingScale();
        $lines = [];

        foreach (range($scale['max'], $scale['min'], -$scale['step']) as $stars) {
            $lines[] = $stars.' stars: '.($counts[$stars] ?? 0);
        }

        $this->assertSame(['5 stars: 2', '4 stars: 0', '3 stars: 0', '2 stars: 1', '1 stars: 0'], $lines);
    }

    public function test_the_top_rated_list_orders_by_its_average(): void
    {
        $best = Product::create();
        $good = Product::create();
        $low = Product::create();
        User::create()->rate($best, 5);
        User::create()->rate($good, 4);
        User::create()->rate($low, 2);

        $products = Product::whereRatingAtLeast(4)
            ->withCount([
                'ratings',
                'ratings as ratings_avg_value' => function ($query) {
                    $query->select(DB::raw('avg(value)'));
                },
            ])
            ->orderByDesc('ratings_avg_value')
            ->take(10)
            ->get();

        $this->assertSame([$best->getKey(), $good->getKey()], $products->modelKeys());
    }

    public function test_the_form_flow_validates_against_the_scale_and_clears_an_empty_comment(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4, 'Nice');

        $scale = $product->ratingScale();
        $data = validator(['value' => '5', 'comment' => null], [
            'value' => ['required', 'numeric', 'min:'.$scale['min'], 'max:'.$scale['max']],
            'comment' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $result = $user->rate($product, $data['value'], $data['comment'] ?? null);

        $this->assertTrue($result->updated());
        $this->assertSame(5, $result->current());
        $this->assertNull($user->ratingFor($product)?->comment);
    }
}
