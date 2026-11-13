<?php

namespace Pharaonic\Laravel\Ratings\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use Pharaonic\Laravel\Ratings\RatingsServiceProvider;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Schema;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetMorphMap();
        $this->freshDatabase();
    }

    protected function tearDown(): void
    {
        $this->resetMorphMap();

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [RatingsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connection());
    }

    /**
     * The test database: in-memory SQLite, unless RATINGS_DB selects mysql or pgsql.
     *
     * @return array<string, mixed>
     */
    public static function connection(): array
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return is_string($value) && $value !== '' ? $value : $default;
        };

        switch ($env('RATINGS_DB', 'sqlite')) {
            case 'mysql':
                return [
                    'driver' => 'mysql',
                    'host' => $env('RATINGS_DB_HOST', '127.0.0.1'),
                    'port' => $env('RATINGS_DB_PORT', '3306'),
                    'database' => $env('RATINGS_DB_DATABASE', 'ratings'),
                    'username' => $env('RATINGS_DB_USERNAME', 'root'),
                    'password' => $env('RATINGS_DB_PASSWORD', ''),
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'prefix' => '',
                    'strict' => true,
                    'engine' => null,
                ];
            case 'pgsql':
                return [
                    'driver' => 'pgsql',
                    'host' => $env('RATINGS_DB_HOST', '127.0.0.1'),
                    'port' => $env('RATINGS_DB_PORT', '5432'),
                    'database' => $env('RATINGS_DB_DATABASE', 'ratings'),
                    'username' => $env('RATINGS_DB_USERNAME', 'postgres'),
                    'password' => $env('RATINGS_DB_PASSWORD', ''),
                    'charset' => 'utf8',
                    'prefix' => '',
                    'schema' => 'public',
                    'sslmode' => 'prefer',
                ];
            default:
                return [
                    'driver' => 'sqlite',
                    'database' => $env('RATINGS_DB_DATABASE', ':memory:'),
                    'prefix' => '',
                ];
        }
    }

    /**
     * Drop every table, run the package migration, and create the fixture tables.
     */
    protected function freshDatabase(): void
    {
        $this->app->make(Kernel::class)->call('migrate:fresh');

        Schema::create();
    }

    /**
     * Move the tests to a database other processes and connections can open:
     * a SQLite file instead of the in-memory database. Returns the environment
     * a worker process needs to open it.
     *
     * @return array<string, string>
     */
    protected function useSharedDatabase(): array
    {
        $variables = ['RATINGS_DB', 'RATINGS_DB_HOST', 'RATINGS_DB_PORT', 'RATINGS_DB_DATABASE', 'RATINGS_DB_USERNAME', 'RATINGS_DB_PASSWORD'];
        $env = [];

        foreach ($variables as $name) {
            $value = getenv($name);

            if (is_string($value) && $value !== '') {
                $env[$name] = $value;
            }
        }

        if ($this->app['config']->get('database.connections.testing.driver') === 'sqlite') {
            $file = tempnam(sys_get_temp_dir(), 'laravel-ratings-');
            $this->assertIsString($file);

            $this->app['config']->set('database.connections.testing.database', $file);
            DB::purge('testing');
            $this->freshDatabase();

            $this->beforeApplicationDestroyed(function () use ($file) {
                DB::disconnect('testing');
                @unlink($file);
            });

            $env['RATINGS_DB'] = 'sqlite';
            $env['RATINGS_DB_DATABASE'] = $file;
        }

        return $env;
    }

    private function resetMorphMap(): void
    {
        Relation::$morphMap = [];

        if (method_exists(Relation::class, 'requireMorphMap')) {
            Relation::requireMorphMap(false);
        }
    }
}
