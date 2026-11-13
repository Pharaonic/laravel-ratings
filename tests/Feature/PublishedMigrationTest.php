<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Post;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Schema as Fixtures;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

/**
 * The migration is published before anything runs, in a process of its own:
 * a PHP process can declare the migration class from one file only, and an
 * application only ever loads one file per migration name.
 *
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
class PublishedMigrationTest extends TestCase
{
    private const MIGRATION = '2026_11_13_000001_create_pharaonic_ratings_table';

    private string $published;

    protected function freshDatabase(): void
    {
        $this->published = $this->app->databasePath('migrations/'.self::MIGRATION.'.php');
        @unlink($this->published);

        $this->assertSame(0, $this->app->make(Kernel::class)->call('vendor:publish', ['--tag' => 'ratings-migrations']));

        parent::freshDatabase();
    }

    protected function tearDown(): void
    {
        @unlink($this->published);

        parent::tearDown();
    }

    public function test_publishing_copies_the_dated_migration(): void
    {
        $this->assertFileExists($this->published);
        $this->assertFileEquals(__DIR__.'/../../database/migrations/'.self::MIGRATION.'.php', $this->published);
    }

    public function test_the_published_migration_replaces_the_package_one_instead_of_running_twice(): void
    {
        $kernel = $this->app->make(Kernel::class);

        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        $this->assertSame(0, $kernel->call('migrate:status'));
        $this->assertSame(1, substr_count($kernel->output(), self::MIGRATION));

        $this->assertSame(0, $kernel->call('migrate:rollback'));
        $this->assertFalse(Schema::hasTable('ratings'));

        $this->assertSame(0, $kernel->call('migrate'));
        $this->assertTrue(Schema::hasTable('ratings'));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        $this->assertSame(0, $kernel->call('migrate:fresh'));
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());

        Fixtures::create();
        $this->assertTrue(User::create()->rate(Post::create(), 5)->created());
    }
}
