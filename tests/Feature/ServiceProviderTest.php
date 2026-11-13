<?php

namespace Pharaonic\Laravel\Ratings\Tests\Feature;

use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\RatingsServiceProvider;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_the_provider_is_registered(): void
    {
        $this->assertNotEmpty($this->app->getProviders(RatingsServiceProvider::class));
    }

    public function test_the_configuration_defaults_are_merged(): void
    {
        $this->assertSame('ratings', $this->app['config']->get('pharaonic.ratings.table'));
        $this->assertSame(Rating::class, $this->app['config']->get('pharaonic.ratings.model'));
        $this->assertSame(1, $this->app['config']->get('pharaonic.ratings.min'));
        $this->assertSame(5, $this->app['config']->get('pharaonic.ratings.max'));
        $this->assertSame(1, $this->app['config']->get('pharaonic.ratings.step'));
        $this->assertTrue($this->app['config']->get('pharaonic.ratings.cleanup_on_delete'));
    }

    public function test_the_configuration_is_published_to_config_pharaonic(): void
    {
        $source = realpath(__DIR__.'/../../config/ratings.php');

        foreach (['pharaonic', 'laravel-ratings', 'pharaonic-config', 'ratings-config'] as $tag) {
            $paths = ServiceProvider::pathsToPublish(RatingsServiceProvider::class, $tag);
            $published = [];

            foreach ($paths as $from => $to) {
                $published[realpath($from) ?: $from] = $to;
            }

            $this->assertArrayHasKey($source, $published, $tag);
            $this->assertSame($this->app->configPath('pharaonic/ratings.php'), $published[$source]);
        }
    }

    public function test_the_migrations_are_published_to_database_migrations(): void
    {
        $source = realpath(__DIR__.'/../../database/migrations');

        foreach (['pharaonic', 'laravel-ratings', 'pharaonic-migrations', 'ratings-migrations'] as $tag) {
            $published = [];

            foreach (ServiceProvider::pathsToPublish(RatingsServiceProvider::class, $tag) as $from => $to) {
                $published[realpath($from) ?: $from] = $to;
            }

            $this->assertArrayHasKey($source, $published, $tag);
            $this->assertSame($this->app->databasePath('migrations'), $published[$source]);
        }
    }
}
