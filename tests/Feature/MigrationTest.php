<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Post;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;
use RuntimeException;

class MigrationTest extends TestCase
{
    private const MIGRATION = '2026_11_13_000001_create_pharaonic_ratings_table';

    public function test_migrate_creates_the_ratings_table(): void
    {
        $this->assertTrue(Schema::hasTable('ratings'));
        $this->assertTrue(Schema::hasColumns('ratings', [
            'id', 'rater_type', 'rater_id', 'rateable_type', 'rateable_id', 'value', 'comment', 'created_at', 'updated_at',
        ]));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_the_unique_index_refuses_a_second_row_for_a_pair(): void
    {
        $row = ['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5];
        DB::table('ratings')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('ratings')->insert($row);
    }

    public function test_the_value_column_refuses_null_and_the_comment_column_allows_it(): void
    {
        DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5, 'comment' => null]);
        $this->assertNull(DB::table('ratings')->value('comment'));

        $this->expectException(QueryException::class);

        DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '2', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => null]);
    }

    public function test_the_value_column_keeps_four_decimal_places(): void
    {
        DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => '999999.9999']);

        $this->assertSame('999999.9999', number_format((float) DB::table('ratings')->value('value'), 4, '.', ''));
    }

    public function test_the_comment_column_holds_the_longest_comment(): void
    {
        $comment = str_repeat('é', 32767).'x';
        $this->assertSame(65535, strlen($comment));

        $this->assertTrue(User::create()->rate(Post::create(), 5, $comment)->created());
        $this->assertSame($comment, DB::table('ratings')->value('comment'));
    }

    public function test_the_unique_index_allows_the_same_ids_with_other_types(): void
    {
        DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5]);
        DB::table('ratings')->insert(['rater_type' => 'user', 'rater_id' => '1', 'rateable_type' => 'comment', 'rateable_id' => '1', 'value' => 5]);
        DB::table('ratings')->insert(['rater_type' => 'bot', 'rater_id' => '1', 'rateable_type' => 'post', 'rateable_id' => '1', 'value' => 5]);

        $this->assertSame(3, DB::table('ratings')->count());
    }

    public function test_rollback_drops_the_table_and_migrate_creates_it_again(): void
    {
        $this->artisanCall('migrate:rollback');
        $this->assertFalse(Schema::hasTable('ratings'));

        $this->artisanCall('migrate');
        $this->assertTrue(Schema::hasTable('ratings'));
        $this->assertTrue(User::create()->rate(Post::create(), 5)->created());
    }

    public function test_the_configured_table_name_is_used_by_the_migration_and_the_model(): void
    {
        $this->artisanCall('migrate:rollback');
        $this->app['config']->set('pharaonic.ratings.table', 'scores');
        $this->artisanCall('migrate');

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
    }

    public function test_an_existing_ratings_table_stops_the_migration_with_an_explanation(): void
    {
        $this->artisanCall('migrate:rollback');

        // A ratings table the application already had, with its own columns.
        Schema::create('ratings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('rateable_id');
            $table->string('rateable_type');
            $table->integer('stars');
            $table->timestamps();
        });

        try {
            $this->artisanCall('migrate');
            $this->fail('The migration ran over an existing table.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('The [ratings] table already exists', $exception->getMessage());
            $this->assertStringContainsString('Set pharaonic.ratings.table to another name', $exception->getMessage());
        }

        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function artisanCall(string $command, array $parameters = []): void
    {
        $this->assertSame(0, $this->app->make(Kernel::class)->call($command, $parameters));
    }
}
