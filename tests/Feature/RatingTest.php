<?php

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingResult;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Bot;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Comment;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

test('rate creates the rating and reports it', function () {
    $user = User::create();
    $product = Product::create();

    $result = $user->rate($product, 5);

    $this->assertInstanceOf(RatingResult::class, $result);
    $this->assertTrue($result->created());
    $this->assertFalse($result->updated());
    $this->assertFalse($result->unchanged());
    $this->assertTrue($result->changed());
    $this->assertSame('created', $result->status());
    $this->assertNull($result->previous());
    $this->assertSame(5, $result->current());

    $rating = $result->rating();
    $this->assertInstanceOf(Rating::class, $rating);
    $this->assertTrue($rating->exists);
    $this->assertSame(5, $rating->value);
    $this->assertNull($rating->comment);
    $this->assertSame(User::class, $rating->rater_type);
    $this->assertSame((string) $user->getKey(), $rating->rater_id);
    $this->assertSame(Product::class, $rating->rateable_type);
    $this->assertSame((string) $product->getKey(), $rating->rateable_id);
    $this->assertSame(1, Rating::query()->count());
});

test('rating again with another value updates the one row', function () {
    $user = User::create();
    $product = Product::create();
    $id = $user->rate($product, 5)->rating()->getKey();

    $result = $user->rate($product, 3);

    $this->assertTrue($result->updated());
    $this->assertTrue($result->changed());
    $this->assertSame('updated', $result->status());
    $this->assertSame(5, $result->previous());
    $this->assertSame(3, $result->current());
    $this->assertSame($id, $result->rating()->getKey());
    $this->assertSame(1, Rating::query()->count());
    $this->assertSame(3, $user->ratingFor($product)?->value);
});

test('rating again with the same value writes nothing', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');
    $updatedAt = (string) DB::table('ratings')->value('updated_at');

    DB::enableQueryLog();
    $result = $user->rate($product, 4);
    $writes = array_filter(DB::getQueryLog(), function (array $query) {
        // On SQLite, the write takes the lock with an UPDATE that matches no row.
        return preg_match('/^\s*(insert|update|delete)/i', $query['query']) === 1
            && ! str_ends_with($query['query'], 'where 0 = 1');
    });

    $this->assertTrue($result->unchanged());
    $this->assertFalse($result->changed());
    $this->assertSame('unchanged', $result->status());
    $this->assertSame(4, $result->previous());
    $this->assertSame(4, $result->current());
    $this->assertSame([], $writes);
    $this->assertSame($updatedAt, (string) DB::table('ratings')->value('updated_at'));
});

test('the same value in another form is unchanged', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    foreach ([4.0, '4', '4.0', '4.0000', '04'] as $same) {
        $this->assertTrue($user->rate($product, $same)->unchanged(), var_export($same, true));
    }
});

test('an omitted comment is null on a new rating', function () {
    $this->assertNull(User::create()->rate(Product::create(), 5)->rating()->comment);
});

test('a comment is stored on a new rating', function () {
    $rating = User::create()->rate(Product::create(), 4, 'Nice')->rating();

    $this->assertSame('Nice', $rating->comment);
    $this->assertSame('Nice', DB::table('ratings')->value('comment'));
});

test('an explicit null comment on a new rating stores null', function () {
    $result = User::create()->rate(Product::create(), 4, null);

    $this->assertTrue($result->created());
    $this->assertNull($result->rating()->comment);
});

test('an omitted comment keeps the existing comment', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');

    $result = $user->rate($product, 5);

    $this->assertTrue($result->updated());
    $this->assertSame('Nice', $result->rating()->comment);
    $this->assertSame('Nice', $user->ratingFor($product)?->comment);
});

test('a new comment replaces the existing one', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');

    $result = $user->rate($product, 3, 'Changed my mind');

    $this->assertTrue($result->updated());
    $this->assertSame(3, $user->ratingFor($product)?->value);
    $this->assertSame('Changed my mind', $user->ratingFor($product)?->comment);
});

test('an explicit null comment clears the existing one', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');

    $result = $user->rate($product, 4, null);

    $this->assertTrue($result->updated());
    $this->assertSame(4, $result->previous());
    $this->assertSame(4, $result->current());
    $this->assertNull($user->ratingFor($product)?->comment);
});

test('a named null comment clears the existing one', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');
    $product->rateBy($user, 4, 'Nice');

    $this->assertTrue($user->rate($product, 3, comment: null)->updated());
    $this->assertNull($user->ratingFor($product)?->comment);

    $user->rate($product, 3, 'Again');
    $this->assertTrue($product->rateBy($user, 3, comment: null)->updated());
    $this->assertNull($user->ratingFor($product)?->comment);
});

test('the same value with another comment is an update', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');

    $result = $user->rate($product, 4, 'Different');

    $this->assertTrue($result->updated());
    $this->assertSame(4, $result->previous());
    $this->assertSame(4, $result->current());
    $this->assertSame('Different', $user->ratingFor($product)?->comment);
});

test('the same value and comment is unchanged', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');

    $this->assertTrue($user->rate($product, 4, 'Nice')->unchanged());
    $this->assertTrue($user->rate($product, 4)->unchanged());
});

test('clearing a comment that is already empty is unchanged', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    $this->assertTrue($user->rate($product, 4, null)->unchanged());
    $this->assertTrue($user->rate($product, 4, '')->unchanged());
});

test('rate by is the same write with the same comment rules', function () {
    $user = User::create();
    $product = Product::create();

    $created = $product->rateBy($user, 4, 'Nice');
    $this->assertTrue($created->created());
    $this->assertSame('Nice', $created->rating()->comment);

    $kept = $product->rateBy($user, 5);
    $this->assertTrue($kept->updated());
    $this->assertSame(4, $kept->previous());
    $this->assertSame('Nice', $kept->rating()->comment);

    $this->assertTrue($product->rateBy($user, 5)->unchanged());
    $this->assertTrue($user->rate($product, 5)->unchanged());

    $cleared = $product->rateBy($user, 5, null);
    $this->assertTrue($cleared->updated());
    $this->assertNull($cleared->rating()->comment);

    $this->assertSame(1, Rating::query()->count());
    $this->assertSame($created->rating()->getKey(), $user->ratingFor($product)?->getKey());
});

test('the reads answer from both sides', function () {
    $user = User::create();
    $product = Product::create();
    $other = Product::create();

    $this->assertFalse($user->hasRated($product));
    $this->assertFalse($product->ratedBy($user));
    $this->assertNull($user->ratingFor($product));
    $this->assertNull($product->ratingFrom($user));

    $rating = $user->rate($product, 4)->rating();

    $this->assertTrue($user->hasRated($product));
    $this->assertTrue($product->ratedBy($user));
    $this->assertFalse($user->hasRated($other));
    $this->assertSame($rating->getKey(), $user->ratingFor($product)?->getKey());
    $this->assertSame($rating->getKey(), $product->ratingFrom($user)?->getKey());
    $this->assertInstanceOf(Rating::class, $user->ratingFor($product));
});

test('rating for only reads', function () {
    $user = User::create();
    $product = Product::create();

    DB::enableQueryLog();
    $this->assertNull($user->ratingFor($product));
    $user->rate($product, 3);
    DB::flushQueryLog();

    $this->assertSame(3, $user->ratingFor($product)?->value);
    $this->assertSame(1, count(DB::getQueryLog()));
    $this->assertStringStartsWith('select', DB::getQueryLog()[0]['query']);
});

test('unrate removes the rating once', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    $this->assertTrue($user->unrate($product));
    $this->assertFalse($user->unrate($product));
    $this->assertFalse($user->hasRated($product));
    $this->assertSame(0, Rating::query()->count());

    $user->rate($product, 2);
    $this->assertTrue($product->unrateBy($user));
    $this->assertFalse($product->unrateBy($user));
    $this->assertSame(0, Rating::query()->count());
});

test('a removed rating is created again', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4, 'Nice');
    $user->unrate($product);

    $result = $user->rate($product, 2);

    $this->assertTrue($result->created());
    $this->assertNull($result->previous());
    $this->assertNull($result->rating()->comment);
});

test('the relations are morph many and compose', function () {
    $user = User::create();
    $bot = Bot::create();
    $first = Product::create();
    $second = Product::create();
    $user->rate($first, 4);
    $user->rate($second, 5);
    $bot->rate($first, 2);

    $this->assertInstanceOf(MorphMany::class, $user->givenRatings());
    $this->assertInstanceOf(MorphMany::class, $first->ratings());
    $this->assertSame(2, $user->givenRatings()->count());
    $this->assertSame(2, $first->ratings()->count());
    $this->assertSame([4, 5], $user->givenRatings()->orderBy('value')->get()->pluck('value')->all());
    $this->assertSame(1, $user->givenRatings()->where('value', '>=', 5)->count());
    $this->assertSame(
        [$first->getKey(), $second->getKey()],
        $user->givenRatings()->with('rateable')->orderBy('id')->get()->pluck('rateable.id')->all()
    );
    $this->assertSame(2, $first->ratings()->paginate(1)->total());

    $products = Product::with('ratings')->withCount('ratings')->orderBy('id')->get();
    $this->assertSame([2, 1], $products->pluck('ratings_count')->map(function ($count) {
        return (int) $count;
    })->all());
    $this->assertCount(2, $products[0]->ratings);
    $this->assertSame([$first->getKey()], Product::whereHas('ratings', function ($query) {
        $query->where('value', '<', 3);
    })->pluck('id')->all());
});

test('the rating relates back to both models', function () {
    $user = User::create();
    $product = Product::create();
    $rating = $user->rate($product, 4)->rating()->fresh();

    $this->assertInstanceOf(Rating::class, $rating);
    $this->assertTrue($rating->rater?->is($user));
    $this->assertTrue($rating->rateable?->is($product));
    $this->assertSame(1, Rating::query()->whereRater($user)->whereRateable($product)->count());
    $this->assertSame(0, Rating::query()->whereRater(Bot::create())->count());
});

test('the rating is updated with plain eloquent', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 5, 'Great');

    $rating = $user->ratingFor($product);
    $this->assertNotNull($rating);

    $this->assertTrue($rating->update(['comment' => 'Updated review']));
    $this->assertSame(5, $user->ratingFor($product)?->value);
    $this->assertSame('Updated review', $user->ratingFor($product)?->comment);

    $this->assertTrue($rating->update(['value' => 4, 'comment' => 'Changed my mind']));
    $this->assertSame(4, $user->ratingFor($product)?->value);
    $this->assertSame('Changed my mind', $user->ratingFor($product)?->comment);

    $rating->comment = null;
    $this->assertTrue($rating->save());
    $this->assertNull($user->ratingFor($product)?->comment);
});

test('any model can rate and be rated', function () {
    $bot = Bot::create();
    $comment = Comment::create();
    $user = User::create();

    $this->assertTrue($bot->rate($comment, 3)->created());
    $this->assertTrue($bot->rate($user, 4)->created());
    $this->assertTrue($user->rate($user, 5)->created());
    $this->assertTrue($bot->hasRated($comment));
    $this->assertTrue($user->ratedBy($user));
    $this->assertSame(3, Rating::query()->count());
});

test('a model with both traits keeps given and received ratings apart', function () {
    $alice = User::create();
    $bob = User::create();
    $alice->rate($bob, 5);

    $this->assertSame(1, $alice->givenRatings()->count());
    $this->assertSame(0, $alice->ratings()->count());
    $this->assertSame(0, $bob->givenRatings()->count());
    $this->assertSame(1, $bob->ratings()->count());
    $this->assertTrue($alice->hasRated($bob));
    $this->assertFalse($bob->hasRated($alice));
});

test('writes with an unsaved model throw and write nothing', function () {
    $user = User::create();
    $product = Product::create();
    $writes = [
        function () use ($product) {
            (new User)->rate($product, 4);
        },
        function () use ($user) {
            $user->rate(new Product, 4);
        },
        function () use ($user) {
            (new Product)->rateBy($user, 4);
        },
        function () use ($product) {
            (new User)->unrate($product);
        },
        function () use ($user) {
            (new Product)->unrateBy($user);
        },
    ];

    foreach ($writes as $number => $write) {
        try {
            $write();
            $this->fail("Write {$number} accepted an unsaved model.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('is not saved: save it before it rates or is rated', $exception->getMessage());
        }
    }

    $this->assertSame(0, Rating::query()->count());
});

test('reads and scopes with an unsaved model match nothing', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 4);

    $this->assertFalse((new User)->hasRated($product));
    $this->assertFalse($user->hasRated(new Product));
    $this->assertNull((new User)->ratingFor($product));
    $this->assertNull($product->ratingFrom(new User));
    $this->assertFalse((new Product)->ratedBy($user));
    $this->assertSame(0, (new User)->givenRatings()->count());
    $this->assertSame(0, (new Product)->ratings()->count());
    $this->assertSame(0, Product::whereRatedBy(new User)->count());
    $this->assertSame(0, Rating::query()->whereRater(new User)->count());
    $this->assertNull((new Product)->averageRating());
    $this->assertSame(0, (new Product)->ratingsCount());
});

test('the value returns as an int or a float', function () {
    $user = User::create();
    $product = Product::create();
    $rating = $user->rate($product, '4')->rating();

    $this->assertSame(4, $rating->value);
    $this->assertSame(4, $rating->fresh()?->value);
    $this->assertSame(4, $rating->toArray()['value']);
    $this->assertStringContainsString('"value":4,', $rating->toJson());
});
