<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Pharaonic\Laravel\Ratings\Events\RatingCreated;
use Pharaonic\Laravel\Ratings\Events\RatingRemoved;
use Pharaonic\Laravel\Ratings\Events\RatingUpdated;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;
use RuntimeException;

class EventsTest extends TestCase
{
    private const EVENTS = [RatingCreated::class, RatingUpdated::class, RatingRemoved::class];

    public function test_creating_a_rating_dispatches_rating_created_once(): void
    {
        Event::fake(self::EVENTS);
        $user = User::create();
        $product = Product::create();

        $rating = $user->rate($product, 4, 'Nice')->rating();

        Event::assertDispatchedTimes(RatingCreated::class, 1);
        Event::assertDispatched(RatingCreated::class, function (RatingCreated $event) use ($rating, $user, $product) {
            return $event->rating === $rating && $event->rater === $user && $event->rateable === $product;
        });
        Event::assertNotDispatched(RatingUpdated::class);
    }

    public function test_changing_the_value_dispatches_rating_updated_with_the_previous_state(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4, 'Nice');
        Event::fake(self::EVENTS);

        $rating = $product->rateBy($user, 2)->rating();

        Event::assertDispatchedTimes(RatingUpdated::class, 1);
        Event::assertDispatched(RatingUpdated::class, function (RatingUpdated $event) use ($rating, $user, $product) {
            return $event->rating === $rating && $event->rater === $user && $event->rateable === $product
                && $event->previousValue === 4 && $event->previousComment === 'Nice'
                && $event->rating->value === 2 && $event->rating->comment === 'Nice';
        });
        Event::assertNotDispatched(RatingCreated::class);
    }

    public function test_changing_only_the_comment_dispatches_rating_updated(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4, 'Nice');
        Event::fake(self::EVENTS);

        $user->rate($product, 4, 'Nicer');
        $user->rate($product, 4, null);

        Event::assertDispatchedTimes(RatingUpdated::class, 2);
        Event::assertDispatched(RatingUpdated::class, function (RatingUpdated $event) {
            return $event->previousValue === 4 && $event->previousComment === 'Nicer' && $event->rating->comment === null;
        });
    }

    public function test_an_unchanged_rating_dispatches_nothing(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4, 'Nice');
        Event::fake(self::EVENTS);

        $user->rate($product, 4);
        $user->rate($product, 4, 'Nice');
        $user->rate($product, '4.0');

        Event::assertNotDispatched(RatingCreated::class);
        Event::assertNotDispatched(RatingUpdated::class);
    }

    public function test_removing_a_rating_dispatches_rating_removed_once(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 3, 'Meh');
        Event::fake(self::EVENTS);

        $this->assertTrue($user->unrate($product));
        $this->assertFalse($user->unrate($product));
        $this->assertFalse($product->unrateBy($user));

        Event::assertDispatchedTimes(RatingRemoved::class, 1);
        Event::assertDispatched(RatingRemoved::class, function (RatingRemoved $event) use ($user, $product) {
            return $event->rater === $user && $event->rateable === $product
                && $event->rating->value === 3 && $event->rating->comment === 'Meh' && ! $event->rating->exists;
        });
    }

    public function test_a_refused_write_dispatches_nothing(): void
    {
        Event::fake(self::EVENTS);
        $user = User::create();
        $product = Product::create();

        foreach ([function () use ($user, $product) {
            $user->rate($product, 9);
        }, function () use ($user, $product) {
            $user->rate($product, 4, 42);
        }] as $write) {
            try {
                $write();
                $this->fail('The write was accepted.');
            } catch (InvalidArgumentException $exception) {
            }
        }

        Event::assertNotDispatched(RatingCreated::class);
        $this->assertSame(0, Rating::query()->count());
    }

    public function test_a_cancelled_create_is_thrown_not_reported(): void
    {
        Event::fake(self::EVENTS);
        Event::listen('eloquent.creating: '.Rating::class, function () {
            return false;
        });

        try {
            User::create()->rate(Product::create(), 4);
            $this->fail('A cancelled create was reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A model event cancelled saving the rating, so it was not created.', $exception->getMessage());
        }

        Event::assertNotDispatched(RatingCreated::class);
        $this->assertSame(0, Rating::query()->count());
    }

    public function test_a_cancelled_update_is_thrown_not_reported(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4, 'Nice');
        Event::fake(self::EVENTS);
        Event::listen('eloquent.updating: '.Rating::class, function () {
            return false;
        });

        try {
            $user->rate($product, 5);
            $this->fail('A cancelled update was reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A model event cancelled saving the rating, so it was not updated.', $exception->getMessage());
        }

        Event::assertNotDispatched(RatingUpdated::class);
        $this->assertSame(4, $user->ratingFor($product)?->value);
    }

    public function test_the_model_events_of_the_rating_run_for_package_writes(): void
    {
        $fired = [];

        foreach (['saving', 'creating', 'created', 'updating', 'updated', 'saved'] as $name) {
            Event::listen("eloquent.{$name}: ".Rating::class, function () use (&$fired, $name) {
                $fired[] = $name;
            });
        }

        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4);
        $this->assertSame(['saving', 'creating', 'created', 'saved'], $fired);

        $fired = [];
        $user->rate($product, 5);
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $fired);

        $fired = [];
        $user->rate($product, 5);
        $this->assertSame([], $fired);
    }

    public function test_direct_model_writes_dispatch_model_events_only(): void
    {
        $user = User::create();
        $product = Product::create();
        $rating = $user->rate($product, 4)->rating();
        Event::fake(self::EVENTS);
        $updated = 0;
        Event::listen('eloquent.updated: '.Rating::class, function () use (&$updated) {
            $updated++;
        });

        $rating->update(['value' => 5, 'comment' => 'Direct']);

        $this->assertSame(1, $updated);
        Event::assertNotDispatched(RatingUpdated::class);
    }

    public function test_events_come_after_the_write_and_inside_a_callers_transaction(): void
    {
        $user = User::create();
        $product = Product::create();
        $seen = [];
        Event::listen(RatingCreated::class, function (RatingCreated $event) use (&$seen) {
            // The row is there when the listener runs.
            $seen[] = [DB::transactionLevel(), Rating::query()->whereKey($event->rating->getKey())->exists()];
        });

        $user->rate($product, 4);
        $this->assertSame([[0, true]], $seen);

        $seen = [];
        DB::transaction(function () use ($user) {
            $user->rate(Product::create(), 4);
        });

        // Inside a caller's transaction, the event comes before that transaction commits.
        $this->assertSame([[1, true]], $seen);
    }

    public function test_events_for_a_write_the_caller_rolls_back_are_still_dispatched(): void
    {
        Event::fake(self::EVENTS);
        $user = User::create();
        $product = Product::create();

        DB::beginTransaction();
        $user->rate($product, 4);
        DB::rollBack();

        // Documented: listeners that must only see committed ratings run after the caller's transaction.
        Event::assertDispatchedTimes(RatingCreated::class, 1);
        $this->assertSame(0, Rating::query()->count());
    }
}
