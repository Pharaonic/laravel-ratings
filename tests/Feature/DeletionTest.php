<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Ratings\Events\RatingRemoved;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\SoftDeletableMember;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\SoftDeletablePost;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

test('deleting a rateable deletes its ratings only', function () {
    $user = User::create();
    $deleted = Product::create();
    $kept = Product::create();
    $user->rate($deleted, 4);
    Bot::create()->rate($deleted, 3);
    $user->rate($kept, 5);
    Event::fake([RatingRemoved::class]);

    $deleted->delete();

    $this->assertSame(0, $deleted->ratings()->count());
    $this->assertSame(1, Rating::query()->count());
    $this->assertTrue($user->hasRated($kept));
    Event::assertNotDispatched(RatingRemoved::class);
});

test('deleting a rater deletes the ratings it gave only', function () {
    $deleted = Bot::create();
    $kept = Bot::create();
    $product = Product::create();
    $deleted->rate($product, 4);
    $kept->rate($product, 2);

    $deleted->delete();

    $this->assertSame(1, Rating::query()->count());
    $this->assertSame(2.0, $product->averageRating());
});

test('deleting a model with both traits deletes given and received ratings', function () {
    $alice = User::create();
    $bob = User::create();
    $carol = User::create();
    $alice->rate($bob, 5);
    $bob->rate($alice, 4);
    $bob->rate($carol, 3);
    $alice->rate($carol, 2);

    $bob->delete();

    $this->assertSame(1, Rating::query()->count());
    $this->assertTrue($alice->hasRated($carol));
});

test('only the deleted models type is cleaned up', function () {
    $user = User::create();
    $bot = Bot::create();
    $this->assertSame($user->getKey(), $bot->getKey());
    $product = Product::create();
    $user->rate($product, 4);
    $bot->rate($product, 3);

    $bot->delete();

    $this->assertTrue($user->hasRated($product));
    $this->assertSame(1, Rating::query()->count());
});

test('a soft delete keeps the ratings and a restore brings them back', function () {
    $member = SoftDeletableMember::create();
    $post = SoftDeletablePost::create();
    $member->rate($post, 4, 'Kept');

    $post->delete();
    $member->delete();

    $this->assertSame(1, Rating::query()->count());

    $post->restore();
    $member->restore();

    $this->assertTrue($member->hasRated($post));
    $this->assertSame('Kept', $post->ratingFrom($member)?->comment);
    $this->assertSame(4.0, $post->averageRating());
});

test('a force delete deletes the ratings', function () {
    $member = SoftDeletableMember::create();
    $post = SoftDeletablePost::create();
    $other = SoftDeletablePost::create();
    $member->rate($post, 4);
    $member->rate($other, 2);

    $post->forceDelete();
    $this->assertSame(1, Rating::query()->count());

    $member->delete();
    $this->assertSame(1, Rating::query()->count());

    $member->forceDelete();
    $this->assertSame(0, Rating::query()->count());
});

test('cleanup can be turned off', function () {
    $this->app['config']->set('pharaonic.ratings.cleanup_on_delete', false);
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    $product->delete();
    $user->delete();

    $this->assertSame(1, Rating::query()->count());
});

test('a bulk query delete skips model events and leaves the ratings', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    // Laravel runs no model events for a query delete, so nothing cleans up.
    Product::query()->delete();

    $this->assertSame(1, Rating::query()->count());
    $this->assertSame(1, DB::table('ratings')->where('rateable_type', Product::class)->count());
});
