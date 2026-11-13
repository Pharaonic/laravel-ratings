<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Ratings\Events\RatingCreated;
use Pharaonic\Laravel\Ratings\Events\RatingRemoved;
use Pharaonic\Laravel\Ratings\Events\RatingUpdated;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Course;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\CustomRating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\SecondaryRating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;
use UnexpectedValueException;

class CustomModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CustomRating::$created = 0;
    }

    public function test_the_configured_model_and_its_table_are_used_everywhere(): void
    {
        $this->useModel(CustomRating::class);

        $this->assertTrue(Schema::hasTable('reviews'));
        $this->assertFalse(Schema::hasTable('ratings'));

        Event::fake([RatingCreated::class, RatingUpdated::class, RatingRemoved::class]);
        $user = User::create();
        $product = Product::create();

        $result = $user->rate($product, 5, 'Great');
        $this->assertInstanceOf(CustomRating::class, $result->rating());
        $this->assertTrue($result->rating()->isPositive());
        $this->assertSame(1, CustomRating::$created);

        $this->assertInstanceOf(CustomRating::class, $product->rateBy($user, 2)->rating());
        $this->assertFalse($user->ratingFor($product)?->isPositive());
        $this->assertInstanceOf(CustomRating::class, $user->ratingFor($product));
        $this->assertInstanceOf(CustomRating::class, $product->ratingFrom($user));
        $this->assertInstanceOf(CustomRating::class, $user->givenRatings()->firstOrFail());
        $this->assertInstanceOf(CustomRating::class, $product->ratings()->firstOrFail());
        $this->assertInstanceOf(CustomRating::class, $product->ratingsWithComments()->firstOrFail());
        $this->assertInstanceOf(CustomRating::class, Product::with('ratings')->firstOrFail()->ratings->first());
        $this->assertInstanceOf(CustomRating::class, CustomRating::query()->whereRater($user)->whereRateable($product)->firstOrFail());
        $this->assertTrue($user->hasRated($product));
        $this->assertTrue($product->ratedBy($user));

        // Aggregates and scopes read the configured table.
        $this->assertSame(1, $product->ratingsCount());
        $this->assertSame(2.0, $product->averageRating());
        $this->assertSame(2, $product->ratingsSum());
        $this->assertSame([2 => 1], $product->ratingDistribution());
        $this->assertSame(1, Product::whereRatedBy($user)->count());
        $this->assertSame(1, Product::whereRatingAtLeast(2)->count());
        $this->assertSame(1, (int) Product::withCount('ratings')->firstOrFail()->getAttribute('ratings_count'));
        $this->assertSame(1, DB::table('reviews')->count());

        // Direct saves of the custom model follow the scale too.
        $rating = $user->ratingFor($product);
        $this->assertNotNull($rating);
        $this->assertTrue($rating->update(['value' => 4]));

        try {
            $rating->update(['value' => 9]);
            $this->fail('The custom model saved a value off the scale.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('1 to 5 in steps of 1', $exception->getMessage());
        }

        $this->assertTrue($user->unrate($product));

        foreach ([RatingCreated::class, RatingUpdated::class, RatingRemoved::class] as $event) {
            Event::assertDispatched($event, function ($event) {
                return $event->rating instanceof CustomRating;
            });
        }

        // Cleanup on delete runs on the configured table.
        $user->rate($product, 3);
        $user->rate(Course::create(), 9.5);
        $this->assertSame(2, DB::table('reviews')->count());
        $user->delete();
        $this->assertSame(0, DB::table('reviews')->count());
    }

    public function test_a_model_on_another_connection_gets_its_table_there(): void
    {
        $this->app['config']->set('database.connections.secondary', $this->app['config']->get('database.connections.testing'));
        $this->useModel(SecondaryRating::class);

        $this->assertTrue(Schema::connection('secondary')->hasTable('ratings'));

        if (DB::connection()->getDriverName() === 'sqlite') {
            // Two in-memory SQLite connections are two databases.
            $this->assertFalse(Schema::hasTable('ratings'));
        }

        $user = User::create();
        $product = Product::create();
        $result = $user->rate($product, 4);

        $this->assertInstanceOf(SecondaryRating::class, $result->rating());
        $this->assertSame('secondary', $result->rating()->getConnectionName());
        $this->assertSame(1, DB::connection('secondary')->table('ratings')->count());
        $this->assertTrue($user->rate($product, 5)->updated());
        $this->assertTrue($user->hasRated($product));
        $this->assertCount(1, $user->givenRatings()->get());
        $this->assertSame(5.0, $product->averageRating());
        $this->assertSame([5 => 1], $product->ratingDistribution());
        $this->assertTrue($user->unrate($product));
        $this->assertSame(0, DB::connection('secondary')->table('ratings')->count());
    }

    public function test_the_configured_table_setting_names_the_table_unless_the_model_sets_one(): void
    {
        $this->app['config']->set('pharaonic.ratings.table', 'scores');

        $this->assertSame('scores', (new Rating)->getTable());
        $this->assertSame('reviews', (new CustomRating)->getTable());
    }

    public function test_a_model_that_is_not_a_rating_is_refused(): void
    {
        $this->app['config']->set('pharaonic.ratings.model', Product::class);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('must name '.Rating::class.' or a subclass of it, not ['.Product::class.']');

        User::create()->rate(Product::create(), 4);
    }

    public function test_a_model_setting_that_is_not_a_class_name_is_refused(): void
    {
        $this->app['config']->set('pharaonic.ratings.model', ['not', 'a', 'class']);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not [array]');

        Product::create()->ratings();
    }

    private function useModel(string $class): void
    {
        $kernel = $this->app->make(Kernel::class);
        $this->assertSame(0, $kernel->call('migrate:rollback'));
        $this->app['config']->set('pharaonic.ratings.model', $class);
        $this->assertSame(0, $kernel->call('migrate'));
    }
}
