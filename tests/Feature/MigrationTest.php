<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Post;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

test('migrate creates the ratings table', function () {
    $this->assertTrue(Schema::hasTable('ratings'));
    $this->assertTrue(Schema::hasColumns('ratings', [
        'id', 'rater_type', 'rater_id', 'rateable_type', 'rateable_id', 'value', 'comment', 'created_at', 'updated_at',
    ]));
    $this->assertSame(1, DB::table('migrations')->where('migration', RATINGS_MIGRATION)->count());
});

test('the unique index refuses a second row for a pair', function () {
    $row = ['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5];
    DB::table('ratings')->insert($row);

    $this->expectException(QueryException::class);

    DB::table('ratings')->insert($row);
});

test('the value column refuses null and the comment column allows it', function () {
    DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5, 'comment' => null]);
    $this->assertNull(DB::table('ratings')->value('comment'));

    $this->expectException(QueryException::class);

    DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '2', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => null]);
});

test('the value column keeps four decimal places', function () {
    DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => '999999.9999']);

    $this->assertSame('999999.9999', number_format((float) DB::table('ratings')->value('value'), 4, '.', ''));
});

test('the comment column holds the longest comment', function () {
    $comment = str_repeat('é', 32767).'x';
    $this->assertSame(65535, strlen($comment));

    $this->assertTrue(User::create()->rate(Post::create(), 5, $comment)->created());
    $this->assertSame($comment, DB::table('ratings')->value('comment'));
});

test('the unique index allows the same ids with other types', function () {
    DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5]);
    DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'comment', 'rateable_id' => '1', 'value' => 5]);
    DB::table('ratings')->insert(['rater_type' => 'bot', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5]);

    $this->assertSame(3, DB::table('ratings')->count());
});

test('rollback drops the table and migrate creates it again', function () {
    runArtisan('migrate:rollback');
    $this->assertFalse(Schema::hasTable('ratings'));

    runArtisan('migrate');
    $this->assertTrue(Schema::hasTable('ratings'));
    $this->assertTrue(User::create()->rate(Post::create(), 5)->created());
});

test('the configured table name is used by the migration and the model', function () {
    runArtisan('migrate:rollback');
    $this->app['config']->set('pharaonic.ratings.table', 'scores');
    runArtisan('migrate');

    $this->assertTrue(Schema::hasTable('scores'));
    $this->assertFalse(Schema::hasTable('ratings'));
    $this->assertSame('scores', (new Rating)->getTable());

    $user = User::create();
    $post = Post::create();
    $this->assertSame($user->rate($post, 4)->rating()->getKey(), $user->rate($post, 5)->rating()->getKey());
    $this->assertSame(1, DB::table('scores')->count());
    $this->assertSame(1, Post::whereRatedBy($user)->count());
    $this->assertSame(1, Post::whereRatingAtLeast(5)->count());
    $this->assertSame([5 => 1], $post->ratingDistribution());
    $this->assertSame(1, (int) Post::withCount('ratings')->firstOrFail()->getAttribute('ratings_count'));
});

test('an existing ratings table stops the migration with an explanation', function () {
    runArtisan('migrate:rollback');

    // A ratings table the application already had, with its own columns.
    Schema::create('ratings', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('rateable_id');
        $table->string('rateable_type');
        $table->integer('stars');
        $table->timestamps();
    });

    try {
        runArtisan('migrate');
        $this->fail('The migration ran over an existing table.');
    } catch (RuntimeException $exception) {
        $this->assertStringContainsString('The [ratings] table already exists', $exception->getMessage());
        $this->assertStringContainsString('Set pharaonic.ratings.table to another name', $exception->getMessage());
    }

    $this->assertSame(0, DB::table('migrations')->where('migration', RATINGS_MIGRATION)->count());
});
