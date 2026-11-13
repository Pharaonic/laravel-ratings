<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Course;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

class AggregatesTest extends TestCase
{
    public function test_a_model_without_ratings_has_empty_aggregates(): void
    {
        $product = Product::create();

        $this->assertNull($product->averageRating());
        $this->assertSame(0, $product->ratingsCount());
        $this->assertSame(0, $product->ratingsSum());
        $this->assertSame([], $product->ratingDistribution());
        $this->assertSame(0, $product->ratingsWithComments()->count());
    }

    public function test_count_average_sum_and_distribution(): void
    {
        $product = $this->rated(Product::create(), [5, 4, 4, 2]);
        $this->rated(Product::create(), [1, 1]);

        $this->assertSame(4, $product->ratingsCount());
        $this->assertSame(3.75, $product->averageRating());
        $this->assertSame(15, $product->ratingsSum());
        $this->assertSame([2 => 1, 4 => 2, 5 => 1], $product->ratingDistribution());
    }

    public function test_the_average_is_rounded_to_four_decimal_places(): void
    {
        $product = $this->rated(Product::create(), [5, 4, 4]);

        $this->assertSame(4.3333, $product->averageRating());

        $product = $this->rated(Product::create(), [5, 5, 4]);
        $this->assertSame(4.6667, $product->averageRating());
    }

    public function test_decimal_ratings_aggregate_exactly(): void
    {
        $course = $this->rated(Course::create(), [4.5, 4.5, 10, 0.5, 0]);

        $this->assertSame(5, $course->ratingsCount());
        $this->assertSame(3.9, $course->averageRating());
        $this->assertSame(19.5, $course->ratingsSum());
        $this->assertSame([0 => 1, '0.5' => 1, '4.5' => 2, 10 => 1], $course->ratingDistribution());
    }

    public function test_the_distribution_is_one_grouped_query(): void
    {
        $product = $this->rated(Product::create(), [5, 4, 4, 2, 1, 3]);

        DB::enableQueryLog();
        $distribution = $product->ratingDistribution();

        $this->assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 1], $distribution);
        $this->assertCount(1, DB::getQueryLog());
        $this->assertStringContainsString('group by', strtolower(DB::getQueryLog()[0]['query']));
    }

    public function test_each_aggregate_is_one_query_without_loading_the_ratings(): void
    {
        $product = $this->rated(Product::create(), [5, 4]);

        DB::enableQueryLog();
        $product->averageRating();
        $product->ratingsCount();
        $product->ratingsSum();

        $this->assertCount(3, DB::getQueryLog());
        $this->assertFalse($product->relationLoaded('ratings'));
    }

    public function test_ratings_with_comments_skip_missing_and_blank_comments(): void
    {
        $product = Product::create();
        User::create()->rate($product, 5, 'Great');
        User::create()->rate($product, 4);
        User::create()->rate($product, 3, '   ');
        User::create()->rate($product, 2, '');
        User::create()->rate($product, 1, 'Bad');

        $ratings = $product->ratingsWithComments();

        $this->assertInstanceOf(MorphMany::class, $ratings);
        $this->assertFalse($product->relationLoaded('ratings'));
        $this->assertSame(['Bad', 'Great'], $ratings->orderBy('comment')->pluck('comment')->all());
        $this->assertSame(1, $product->ratingsWithComments()->latest('id')->paginate(1)->count());
        $this->assertSame(2, $product->ratingsWithComments()->paginate(1)->total());
        $this->assertSame(1, $product->ratingsWithComments()->where('value', '>=', 5)->count());
    }

    public function test_where_rated_by_keeps_only_the_raters_models(): void
    {
        $user = User::create();
        $bot = Bot::create();
        $first = Product::create();
        $second = Product::create();
        Product::create();
        $user->rate($first, 4);
        $user->rate($second, 2);
        $bot->rate($second, 5);

        $this->assertSame([$first->getKey(), $second->getKey()], Product::whereRatedBy($user)->orderBy('id')->pluck('id')->all());
        $this->assertSame([$second->getKey()], Product::whereRatedBy($bot)->pluck('id')->all());
        $this->assertSame(1, Product::whereRatedBy($user)->whereRatedBy($bot)->count());
        $this->assertSame(1, Product::whereRatedBy($user)->paginate(1)->count());
        $this->assertSame(2, Product::whereRatedBy($user)->paginate(1)->total());
    }

    public function test_where_rating_at_least_compares_the_average_not_single_ratings(): void
    {
        $high = $this->rated(Product::create(), [5, 4]);        // 4.5
        $mixed = $this->rated(Product::create(), [5, 1]);       // 3: one rating of 5, but the average is low
        $exact = $this->rated(Product::create(), [4, 4]);       // 4
        Product::create();                                       // no ratings

        $this->assertSame(
            [$high->getKey(), $exact->getKey()],
            Product::whereRatingAtLeast(4)->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame([$high->getKey()], Product::whereRatingAtLeast(4.5)->pluck('id')->all());
        $this->assertSame([$high->getKey()], Product::whereRatingAtLeast('4.25')->pluck('id')->all());
        $this->assertSame(
            [$high->getKey(), $mixed->getKey(), $exact->getKey()],
            Product::whereRatingAtLeast(1)->orderBy('id')->pluck('id')->all()
        );
        $this->assertSame([], Product::whereRatingAtLeast(5)->pluck('id')->all());
    }

    public function test_where_rating_at_least_uses_the_rounded_average(): void
    {
        $product = $this->rated(Product::create(), [5, 5, 4]);  // 4.66666... rounds to 4.6667

        $this->assertSame(4.6667, $product->averageRating());
        $this->assertSame(1, Product::whereRatingAtLeast(4.6667)->count());
        $this->assertSame(0, Product::whereRatingAtLeast(4.6668)->count());
    }

    public function test_where_rating_at_least_works_on_decimal_scales(): void
    {
        $course = $this->rated(Course::create(), [8.5, 9]);     // 8.75
        $this->rated(Course::create(), [8.5, 8.5]);

        $this->assertSame([$course->getKey()], Course::whereRatingAtLeast(8.75)->pluck('id')->all());
        $this->assertSame(2, Course::whereRatingAtLeast(8.5)->count());
    }

    public function test_where_rating_at_least_composes_with_other_constraints(): void
    {
        $user = User::create();
        $first = $this->rated(Product::create(['name' => 'a']), [5]);
        $second = $this->rated(Product::create(['name' => 'b']), [5]);
        $user->rate($second, 5);

        $this->assertSame([$second->getKey()], Product::whereRatingAtLeast(5)->whereRatedBy($user)->pluck('id')->all());
        $this->assertSame([$first->getKey()], Product::where('name', 'a')->whereRatingAtLeast(4)->pluck('id')->all());
        $this->assertSame(2, Product::whereRatingAtLeast(4)->paginate(1)->total());
        $this->assertSame(1, Product::whereRatingAtLeast(4)->paginate(1)->count());
        $this->assertSame(2, Product::whereRatingAtLeast(4)->withCount('ratings')->get()->count());
    }

    public function test_where_rating_at_least_refuses_a_minimum_that_is_not_a_number(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The minimum average must be a decimal number');

        Product::whereRatingAtLeast('high')->get();
    }

    public function test_counts_and_averages_load_with_the_models(): void
    {
        $first = $this->rated(Product::create(), [5, 4]);
        $second = $this->rated(Product::create(), [2]);
        Product::create();

        DB::enableQueryLog();
        $products = Product::withCount([
            'ratings',
            // withAvg() needs Laravel 8.12; withCount() takes any aggregate column on every line.
            'ratings as ratings_avg_value' => function ($query) {
                $query->select(DB::raw('avg(value)'));
            },
        ])->orderBy('id')->get();

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame([2, 1, 0], $products->pluck('ratings_count')->map(function ($count) {
            return (int) $count;
        })->all());
        $this->assertSame([4.5, 2.0, null], $products->pluck('ratings_avg_value')->map(function ($average) {
            return $average === null ? null : round((float) $average, 4);
        })->all());
        $this->assertSame([$first->getKey(), $second->getKey()], Product::has('ratings')->orderBy('id')->pluck('id')->all());
    }

    /**
     * Rate the model once with each value, each time by a new user.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @param  array<int, int|float>  $values
     * @return T
     */
    private function rated($model, array $values)
    {
        foreach ($values as $value) {
            User::create()->rate($model, $value);
        }

        return $model;
    }
}
