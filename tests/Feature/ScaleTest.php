<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\BrokenScale;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Comment;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Course;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\SoftDeletablePost;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

test('the default scale is one to five in whole steps', function () {
    $user = User::create();
    $product = Product::create();

    $this->assertSame(['min' => 1, 'max' => 5, 'step' => 1], $product->ratingScale());

    foreach ([1, 2, 3, 4, 5] as $value) {
        $this->assertSame($value, $user->rate($product, $value)->current());
    }

    foreach ([0, 6, 4.5, -1, '5.5'] as $value) {
        assertRatingRefused(function () use ($user, $product, $value) {
            $user->rate($product, $value);
        }, 'is not on the rating scale of ['.Product::class.']: 1 to 5 in steps of 1');
    }

    $this->assertSame(5, $user->ratingFor($product)?->value);
});

test('the configured scale applies to every model without its own', function () {
    $this->app['config']->set('pharaonic.ratings.min', 0);
    $this->app['config']->set('pharaonic.ratings.max', 10);
    $this->app['config']->set('pharaonic.ratings.step', 2);
    $user = User::create();

    $this->assertSame(['min' => 0, 'max' => 10, 'step' => 2], (new Product)->ratingScale());
    $this->assertSame(10, $user->rate(Product::create(), 10)->current());
    $this->assertSame(0, $user->rate(Comment::create(), 0)->current());

    assertRatingRefused(function () use ($user) {
        $user->rate(Product::create(), 5);
    }, '0 to 10 in steps of 2');
});

test('a model scale takes decimal steps', function () {
    $user = User::create();
    $course = Course::create();

    $this->assertSame(['min' => 0, 'max' => 10, 'step' => 0.5], $course->ratingScale());

    $this->assertSame(4.5, $user->rate($course, 4.5)->current());
    $this->assertSame(4.5, $user->ratingFor($course)?->value);
    $this->assertSame(8, $user->rate($course, '8.0')->current());
    $this->assertSame(0, $user->rate($course, 0)->current());
    $this->assertSame(10, $user->rate($course, 10)->current());

    foreach ([8.75, 10.5, -0.5, '4.25'] as $value) {
        assertRatingRefused(function () use ($user, $course, $value) {
            $user->rate($course, $value);
        }, '0 to 10 in steps of 0.5');
    }
});

test('quarter steps and four decimal places are exact', function () {
    $this->app['config']->set('pharaonic.ratings.min', 0);
    $this->app['config']->set('pharaonic.ratings.max', 10);
    $this->app['config']->set('pharaonic.ratings.step', '0.25');
    $user = User::create();
    $product = Product::create();

    $this->assertSame(8.75, $user->rate($product, 8.75)->current());
    $this->assertSame(8.75, $user->ratingFor($product)?->value);

    $this->app['config']->set('pharaonic.ratings.step', 0.0001);
    $this->assertSame(9.9999, $user->rate($product, 9.9999)->current());
    $this->assertSame(9.9999, $user->ratingFor($product)?->value);
});

test('float arithmetic noise is not refused', function () {
    $this->app['config']->set('pharaonic.ratings.min', 0);
    $this->app['config']->set('pharaonic.ratings.max', 1);
    $this->app['config']->set('pharaonic.ratings.step', 0.1);
    $user = User::create();
    $product = Product::create();

    // 0.1 + 0.2 is 0.30000000000000004 as a float.
    $this->assertSame(0.3, $user->rate($product, 0.1 + 0.2)->current());
    $this->assertSame(0.3, $user->ratingFor($product)?->value);
    $this->assertSame(0.7, $user->rate($product, 0.7)->current());
    $this->assertSame('0.7000', number_format((float) DB::table('ratings')->value('value'), 4, '.', ''));
});

test('values that are not numbers are refused before any write', function () {
    $user = User::create();
    $product = Product::create();

    $invalid = [
        'null' => [null, 'must be an integer, a float, or a decimal string, not null'],
        'true' => [true, 'not bool'],
        'array' => [[4], 'not array'],
        'object' => [new \stdClass, 'not stdClass'],
        'NAN' => [NAN, 'must be a finite number'],
        'INF' => [INF, 'must be a finite number'],
        'word' => ['four', 'must be a decimal number such as 4 or 4.5, not [four]'],
        'empty' => ['', 'must be a decimal number'],
        'padded' => [' 4', 'must be a decimal number'],
        'exponent' => ['4e0', 'must be a decimal number'],
        'comma' => ['4,5', 'must be a decimal number'],
        'trailing dot' => ['4.', 'must be a decimal number'],
        'leading dot' => ['.5', 'must be a decimal number'],
        'hex' => ['0x4', 'must be a decimal number'],
        'too precise string' => ['4.00001', 'has more than 4 decimal places'],
        'too precise float' => [4.55555, 'has more than 4 decimal places'],
        'too large' => ['1000000', 'is out of range: it may have up to 6 whole digits'],
        'huge float' => [1e300, 'is out of range'],
        'huge string' => [str_repeat('9', 400), 'is out of range'],
    ];

    foreach ($invalid as $name => [$value, $message]) {
        try {
            $user->rate($product, $value);
            $this->fail("The {$name} value was accepted.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage(), $name);
        }
    }

    $this->assertSame(0, Rating::query()->count());
});

test('an invalid model scale is reported with its source', function () {
    try {
        User::create()->rate(BrokenScale::create(), 3);
        $this->fail('An invalid scale was used.');
    } catch (UnexpectedValueException $exception) {
        $this->assertSame('The ratingScale() of ['.BrokenScale::class.']: max must be greater than min.', $exception->getMessage());
    }

    $this->assertSame(0, Rating::query()->count());
});

test('an invalid configured scale is reported with its source', function () {
    $cases = [
        [['step' => 0], 'the step must be greater than 0'],
        [['step' => -1], 'the step must be greater than 0'],
        [['min' => 5, 'max' => 5], 'max must be greater than min'],
        [['step' => 3], 'max - min must be a whole number of steps'],
        [['max' => 'ten'], 'The pharaonic.ratings min, max and step settings: The max must be a decimal number'],
        [['min' => 0.00001], 'The min [1.0E-5] has more than 4 decimal places'],
    ];

    foreach ($cases as [$settings, $message]) {
        $this->app['config']->set('pharaonic.ratings', array_merge(
            $this->app['config']->get('pharaonic.ratings'), ['min' => 1, 'max' => 5, 'step' => 1], $settings
        ));

        try {
            User::create()->rate(Product::create(), 1);
            $this->fail('An invalid scale was used: '.json_encode($settings));
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    $this->assertSame(0, Rating::query()->count());
});

test('direct saves follow the scale', function () {
    $user = User::create();
    $course = Course::create();
    $rating = $user->rate($course, 5)->rating();

    $this->assertTrue($rating->update(['value' => 9.5]));
    $this->assertSame(9.5, $user->ratingFor($course)?->value);

    assertRatingRefused(function () use ($rating) {
        $rating->update(['value' => 9.75]);
    }, 'is not on the rating scale of ['.Course::class.']: 0 to 10 in steps of 0.5');

    $fresh = $user->ratingFor($course);
    $this->assertNotNull($fresh);
    $fresh->value = 11;

    assertRatingRefused(function () use ($fresh) {
        $fresh->save();
    }, '0 to 10 in steps of 0.5');

    $this->assertSame(9.5, $user->ratingFor($course)?->value);
});

test('direct writes refuse values that are not numbers', function () {
    $rating = User::create()->rate(Product::create(), 5)->rating();

    foreach ([null, 'five', true, 4.00001] as $value) {
        try {
            $rating->value = $value;
            $this->fail('The value was accepted: '.var_export($value, true));
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('The rating', $exception->getMessage());
        }
    }

    $this->assertSame(5, $rating->value);
});

test('a direct save loads the rateable once to find its scale', function () {
    $user = User::create();
    $course = Course::create();
    $user->rate($course, 5);
    $rating = Rating::query()->firstOrFail();

    DB::enableQueryLog();
    $rating->update(['value' => 7.5]);
    $queries = array_column(DB::getQueryLog(), 'query');

    $this->assertCount(2, $queries);
    $this->assertStringContainsString('courses', $queries[0]);
    $this->assertStringStartsWith('update', $queries[1]);

    // Not kept as a relation, so the rating's array and JSON stay the same.
    $this->assertFalse($rating->relationLoaded('rateable'));
    $this->assertArrayNotHasKey('rateable', $rating->toArray());
});

test('a direct save uses a loaded rateable and the morph map', function () {
    Relation::morphMap(['course' => Course::class]);
    $user = User::create();
    $course = Course::create();
    $user->rate($course, 5);

    $rating = Rating::with('rateable')->firstOrFail();
    $this->assertSame('course', $rating->rateable_type);

    DB::enableQueryLog();
    $rating->update(['value' => 2.5]);
    $this->assertCount(1, DB::getQueryLog());
    $this->assertSame(2.5, $user->ratingFor($course)?->value);

    assertRatingRefused(function () use ($rating) {
        $rating->update(['value' => 2.25]);
    }, 'steps of 0.5');
});

test('a comment only save does not check or load the rateable', function () {
    $user = User::create();
    $course = Course::create();
    $user->rate($course, 5);
    $rating = Rating::query()->firstOrFail();

    DB::enableQueryLog();
    $rating->update(['comment' => 'Only the comment']);

    $this->assertCount(1, DB::getQueryLog());
});

test('a soft deleted rateable still gives its scale', function () {
    $user = User::create();
    $post = SoftDeletablePost::create();
    $user->rate($post, 3);
    $post->delete();

    $rating = Rating::query()->firstOrFail();

    $this->assertTrue($rating->update(['value' => 4]));
    assertRatingRefused(function () use ($rating) {
        $rating->update(['value' => 6]);
    }, '1 to 5 in steps of 1');
});

test('an orphaned rating cannot change its value', function () {
    $user = User::create();
    $course = Course::create();
    $user->rate($course, 5);
    DB::table('courses')->delete();

    $rating = Rating::query()->firstOrFail();

    try {
        $rating->update(['value' => 4]);
        $this->fail('The value of an orphaned rating was saved.');
    } catch (UnexpectedValueException $exception) {
        $this->assertSame(
            'The rateable ['.Course::class.'] ['.$course->getKey().'] of the rating does not exist, so its rating scale is unknown. '
            .'Delete the orphaned rating, or point it at an existing model.',
            $exception->getMessage()
        );
    }

    // The refused value is still dirty on that instance, so saving it again is refused again.
    assertOrphanSaveRefused($rating);

    // Its comment can still be edited, and it can still be deleted.
    $rating = Rating::query()->firstOrFail();
    $this->assertTrue($rating->update(['comment' => 'Still here']));
    $this->assertTrue((bool) $rating->delete());
});

test('a rating with an unknown morph type cannot change its value', function () {
    $user = User::create();
    $user->rate(Product::create(), 5);
    DB::table('ratings')->update(['rateable_type' => 'App\Missing']);

    $this->expectException(UnexpectedValueException::class);
    $this->expectExceptionMessage('The rateable [App\Missing]');

    Rating::query()->firstOrFail()->update(['value' => 4]);
});

test('a scale change never rewrites stored ratings', function () {
    $user = User::create();
    $product = Product::create();
    $user->rate($product, 5, 'Great');

    $this->app['config']->set('pharaonic.ratings.max', 3);

    // Still stored and read as it was.
    $this->assertSame(5, $user->ratingFor($product)?->value);
    $this->assertSame(5.0, $product->averageRating());

    // Only writing the value again checks it.
    $rating = $user->ratingFor($product);
    $this->assertNotNull($rating);
    $this->assertTrue($rating->update(['comment' => 'Still great']));
    $this->assertSame('Still great', $user->ratingFor($product)?->comment);

    assertRatingRefused(function () use ($user, $product) {
        $user->rate($product, 5);
    }, '1 to 3 in steps of 1');
    $this->assertSame(3, $user->rate($product, 3)->current());
});

function assertOrphanSaveRefused(Rating $rating): void
{
    try {
        $rating->update(['comment' => 'Still here']);
        test()->fail('A refused value was saved with the comment.');
    } catch (UnexpectedValueException $exception) {
        test()->assertStringContainsString('does not exist', $exception->getMessage());
    }
}

function assertRatingRefused(callable $write, string $message): void
{
    try {
        $write();
        test()->fail('The value was accepted.');
    } catch (InvalidArgumentException $exception) {
        test()->assertStringContainsString($message, $exception->getMessage());
    }
}
