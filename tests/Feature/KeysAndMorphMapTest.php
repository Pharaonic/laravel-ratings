<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Comment;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Post;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\UlidModel;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\UuidModel;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

class KeysAndMorphMapTest extends TestCase
{
    public function test_the_same_ids_of_different_types_are_different_pairs(): void
    {
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
    }

    public function test_uuid_and_ulid_keys_rate_and_are_rated(): void
    {
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
    }

    public function test_integer_rateables_are_found_by_string_and_integer_keys(): void
    {
        $user = User::create();
        $product = Product::create();
        $user->rate($product, 4);

        $found = Product::query()->findOrFail((string) $product->getKey());

        $this->assertTrue($user->hasRated($found));
        $this->assertSame(1, Product::whereRatingAtLeast(4)->whereKey($product->getKey())->count());
    }

    public function test_a_morph_map_alias_is_stored_and_used_everywhere(): void
    {
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
    }

    public function test_a_model_whose_key_does_not_fit_is_refused(): void
    {
        $user = User::create();
        $long = new UuidModel;
        $long->setAttribute('id', str_repeat('k', 65));
        $long->exists = true;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is 65 characters long; the ratings table stores keys of up to 64 characters');

        $user->rate($long, 3);
    }

    public function test_a_morph_type_that_does_not_fit_is_refused_with_a_hint(): void
    {
        Relation::morphMap([str_repeat('t', 192) => Comment::class]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Register a shorter alias with Relation::morphMap()');

        User::create()->rate(Comment::create(), 3);
    }
}
