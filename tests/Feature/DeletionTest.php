<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Ratings\Events\RatingRemoved;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\SoftDeletableMember;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\SoftDeletablePost;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

class DeletionTest extends TestCase
{
    public function test_deleting_a_rateable_deletes_its_ratings_only(): void
    {
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
    }

    public function test_deleting_a_rater_deletes_the_ratings_it_gave_only(): void
    {
        $deleted = Bot::create();
        $kept = Bot::create();
        $product = Product::create();
        $deleted->rate($product, 4);
        $kept->rate($product, 2);

        $deleted->delete();

        $this->assertSame(1, Rating::query()->count());
        $this->assertSame(2.0, $product->averageRating());
    }

    public function test_deleting_a_model_with_both_traits_deletes_given_and_received_ratings(): void
    {
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
    }

    public function test_only_the_deleted_models_type_is_cleaned_up(): void
    {
        $user = User::create();
        $bot = Bot::create();
        $this->assertSame($user->getKey(), $bot->getKey());
        $product = Product::create();
        $user->rate($product, 4);
        $bot->rate($product, 3);

        $bot->delete();

        $this->assertTrue($user->hasRated($product));
        $this->assertSame(1, Rating::query()->count());
    }

    public function test_a_soft_delete_keeps_the_ratings_and_a_restore_brings_them_back(): void
    {
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
    }

    public function test_a_force_delete_deletes_the_ratings(): void
    {
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
    }

    public function test_cleanup_can_be_turned_off(): void
    {
        $this->app['config']->set('pharaonic.ratings.cleanup_on_delete', false);
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4);

        $product->delete();
        $user->delete();

        $this->assertSame(1, Rating::query()->count());
    }

    public function test_a_bulk_query_delete_skips_model_events_and_leaves_the_ratings(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4);

        // Laravel runs no model events for a query delete, so nothing cleans up.
        Product::query()->delete();

        $this->assertSame(1, Rating::query()->count());
        $this->assertSame(1, DB::table('ratings')->where('rateable_type', Product::class)->count());
    }
}
