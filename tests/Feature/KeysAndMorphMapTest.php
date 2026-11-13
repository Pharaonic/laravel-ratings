<?php

use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Comment;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Post;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\UlidModel;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\UuidModel;

test('the same ids of different types are different pairs', function () {
    $user = User::create();
    $bot = Bot::create();
    $post = Post::create();
    $product = Product::create();
    $this->assertSame($user->getKey(), $bot->getKey());
    $this->assertSame($post->getKey(), $product->getKey());

    $this->assertTrue($user->rate($post, 1)->created());
    $this->assertTrue($user->rate($product, 2)->created());
    $this->assertTrue($bot->rate($post, 3)->created());
    $this->assertTrue($bot->rate($product, 4)->created());

    $this->assertSame(4, Rating::query()->count());
    $this->assertSame(1, $user->ratingFor($post)?->value);
    $this->assertSame(2, $user->ratingFor($product)?->value);
    $this->assertSame(3, $bot->ratingFor($post)?->value);
    $this->assertSame(4, $bot->ratingFor($product)?->value);
    $this->assertSame(2.0, $post->averageRating());
    $this->assertSame(3.0, $product->averageRating());
    $this->assertSame(1, Post::whereRatedBy($bot)->count());
});

test('uuid and ulid keys rate and are rated', function () {
    foreach ([UuidModel::class, UlidModel::class] as $class) {
        $rater = $class::create();
        $rateable = $class::create();
        $this->assertIsString($rater->getKey());

        $this->assertTrue($rater->rate($rateable, 4, 'Keyed')->created());
        $this->assertTrue($rater->rate($rateable, 5)->updated());
        $this->assertTrue($rater->hasRated($rateable));
        $this->assertSame('Keyed', $rateable->ratingFrom($rater)?->comment);
        $this->assertSame(1, $rater->givenRatings()->count());
        $this->assertSame(5.0, $rateable->averageRating());
        $this->assertSame([5 => 1], $rateable->ratingDistribution());
        $this->assertSame([$rateable->getKey()], $class::whereRatedBy($rater)->pluck('id')->all());
        $this->assertSame([$rateable->getKey()], $class::whereRatingAtLeast(5)->pluck('id')->all());
        $this->assertSame(1, (int) $class::withCount('ratings')->whereKey($rateable->getKey())->firstOrFail()->getAttribute('ratings_count'));
        $this->assertCount(1, $class::with('ratings')->whereKey($rateable->getKey())->firstOrFail()->ratings);

        $rating = Rating::query()->whereRateable($rateable)->firstOrFail();
        $this->assertTrue($rating->update(['value' => 3]));
        $this->assertTrue($rater->unrate($rateable));

        $rateable->delete();
        $rater->delete();
    }

    $this->assertSame(0, Rating::query()->count());
});

test('integer rateables are found by string and integer keys', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    $found = Product::query()->findOrFail((string) $product->getKey());

    $this->assertTrue($user->hasRated($found));
    $this->assertSame(1, Product::whereRatingAtLeast(4)->whereKey($product->getKey())->count());
});

test('an enforced morph map refuses an unmapped model before writing', function () {
    if (! method_exists(Relation::class, 'enforceMorphMap')) {
        $this->markTestSkipped('Relation::enforceMorphMap() needs Laravel 8.');
    }

    Relation::enforceMorphMap(['user' => User::class, 'product' => Product::class]);

    $user = User::create();
    $this->assertTrue($user->rate(Product::create(), 4)->created());

    try {
        $user->rate(Post::create(), 4);
        $this->fail('An unmapped model was rated.');
    } catch (ClassMorphViolationException $exception) {
        $this->assertStringContainsString(Post::class, $exception->getMessage());
    }

    $this->assertSame(1, Rating::query()->count());
});

test('a morph map alias is stored and used everywhere', function () {
    Relation::morphMap(['user' => User::class, 'product' => Product::class]);
    $user = User::create();
    $product = Product::create();

    $rating = $user->rate($product, 4)->rating();

    $this->assertSame('user', $rating->rater_type);
    $this->assertSame('product', $rating->rateable_type);
    $this->assertSame(['rater_type' => 'user', 'rateable_type' => 'product'], (array) DB::table('ratings')->select('rater_type', 'rateable_type')->first());
    $this->assertTrue($user->hasRated($product));
    $this->assertTrue($rating->fresh()?->rateable?->is($product));
    $this->assertTrue($rating->fresh()?->rater?->is($user));
    $this->assertSame(1, Product::whereRatedBy($user)->count());
    $this->assertSame(1, Product::whereRatingAtLeast(4)->count());
    $this->assertTrue($user->rate($product, 2)->updated());

    $product->delete();
    $this->assertSame(0, Rating::query()->count());
});

test('a model whose key does not fit is refused', function () {
    $user = User::create();
    $long = new UuidModel;
    $long->setAttribute('id', str_repeat('k', 65));
    $long->exists = true;

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('is 65 characters long; the ratings table stores keys of up to 64 characters');

    $user->rate($long, 3);
});

test('a morph type that does not fit is refused with a hint', function () {
    Relation::morphMap([str_repeat('t', 192) => Comment::class]);

    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Register a shorter alias with Relation::morphMap()');

    User::create()->rate(Comment::create(), 3);
});
