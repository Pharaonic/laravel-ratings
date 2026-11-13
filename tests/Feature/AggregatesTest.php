<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Course;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

test('a model without ratings has empty aggregates', function () {
    $product = Product::create();

    $this->assertNull($product->averageRating());
    $this->assertSame(0, $product->ratingsCount());
    $this->assertSame(0, $product->ratingsSum());
    $this->assertSame([], $product->ratingDistribution());
    $this->assertSame(0, $product->ratingsWithComments()->count());
});

test('count average sum and distribution', function () {
    $product = ratedBy(Product::create(), [5, 4, 4, 2]);
    ratedBy(Product::create(), [1, 1]);

    $this->assertSame(4, $product->ratingsCount());
    $this->assertSame(3.75, $product->averageRating());
    $this->assertSame(15, $product->ratingsSum());
    $this->assertSame([2 => 1, 4 => 2, 5 => 1], $product->ratingDistribution());
});

test('the average is rounded to four decimal places', function () {
    $product = ratedBy(Product::create(), [5, 4, 4]);

    $this->assertSame(4.3333, $product->averageRating());

    $product = ratedBy(Product::create(), [5, 5, 4]);
    $this->assertSame(4.6667, $product->averageRating());
});

test('decimal ratings aggregate exactly', function () {
    $course = ratedBy(Course::create(), [4.5, 4.5, 10, 0.5, 0]);

    $this->assertSame(5, $course->ratingsCount());
    $this->assertSame(3.9, $course->averageRating());
    $this->assertSame(19.5, $course->ratingsSum());
    $this->assertSame([0 => 1, '0.5' => 1, '4.5' => 2, 10 => 1], $course->ratingDistribution());
});

test('the distribution is one grouped query', function () {
    $product = ratedBy(Product::create(), [5, 4, 4, 2, 1, 3]);

    DB::enableQueryLog();
    $distribution = $product->ratingDistribution();

    $this->assertSame([1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 1], $distribution);
    $this->assertCount(1, DB::getQueryLog());
    $this->assertStringContainsString('group by', strtolower(DB::getQueryLog()[0]['query']));
});

test('each aggregate is one query without loading the ratings', function () {
    $product = ratedBy(Product::create(), [5, 4]);

    DB::enableQueryLog();
    $product->averageRating();
    $product->ratingsCount();
    $product->ratingsSum();

    $this->assertCount(3, DB::getQueryLog());
    $this->assertFalse($product->relationLoaded('ratings'));
});

test('ratings with comments skip missing and blank comments', function () {
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
});

test('where rated by keeps only the raters models', function () {
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
});

test('where rating at least compares the average not single ratings', function () {
    $high = ratedBy(Product::create(), [5, 4]);        // 4.5
    $mixed = ratedBy(Product::create(), [5, 1]);       // 3: one rating of 5, but the average is low
    $exact = ratedBy(Product::create(), [4, 4]);       // 4
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
});

test('where rating at least uses the rounded average', function () {
    $product = ratedBy(Product::create(), [5, 5, 4]);  // 4.66666... rounds to 4.6667

    $this->assertSame(4.6667, $product->averageRating());
    $this->assertSame(1, Product::whereRatingAtLeast(4.6667)->count());
    $this->assertSame(0, Product::whereRatingAtLeast(4.6668)->count());
});

test('where rating at least works on decimal scales', function () {
    $course = ratedBy(Course::create(), [8.5, 9]);     // 8.75
    ratedBy(Course::create(), [8.5, 8.5]);

    $this->assertSame([$course->getKey()], Course::whereRatingAtLeast(8.75)->pluck('id')->all());
    $this->assertSame(2, Course::whereRatingAtLeast(8.5)->count());
});

test('where rating at least composes with other constraints', function () {
    $user = User::create();
    $first = ratedBy(Product::create(['name' => 'a']), [5]);
    $second = ratedBy(Product::create(['name' => 'b']), [5]);
    $user->rate($second, 5);

    $this->assertSame([$second->getKey()], Product::whereRatingAtLeast(5)->whereRatedBy($user)->pluck('id')->all());
    $this->assertSame([$first->getKey()], Product::where('name', 'a')->whereRatingAtLeast(4)->pluck('id')->all());
    $this->assertSame(2, Product::whereRatingAtLeast(4)->paginate(1)->total());
    $this->assertSame(1, Product::whereRatingAtLeast(4)->paginate(1)->count());
    $this->assertSame(2, Product::whereRatingAtLeast(4)->withCount('ratings')->get()->count());
});

test('where rating at least refuses a minimum that is not a number', function () {
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('The minimum average must be a decimal number');

    Product::whereRatingAtLeast('high')->get();
});

test('counts and averages load with the models', function () {
    $first = ratedBy(Product::create(), [5, 4]);
    $second = ratedBy(Product::create(), [2]);
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
});

/**
 * Rate the model once with each value, each time by a new user.
 *
 * @template T of \Illuminate\Database\Eloquent\Model
 *
 * @param  T  $model
 * @param  array<int, int|float>  $values
 * @return T
 */
function ratedBy($model, array $values)
{
    foreach ($values as $value) {
        User::create()->rate($model, $value);
    }

    return $model;
}
