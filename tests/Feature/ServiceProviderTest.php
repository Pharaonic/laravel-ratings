<?php

use Illuminate\Support\ServiceProvider;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\RatingsServiceProvider;

test('the provider is registered', function () {
    $this->assertNotEmpty($this->app->getProviders(RatingsServiceProvider::class));
});

test('the configuration defaults are merged', function () {
    $this->assertSame('ratings', $this->app['config']->get('pharaonic.ratings.table'));
    $this->assertSame(Rating::class, $this->app['config']->get('pharaonic.ratings.model'));
    $this->assertSame(1, $this->app['config']->get('pharaonic.ratings.min'));
    $this->assertSame(5, $this->app['config']->get('pharaonic.ratings.max'));
    $this->assertSame(1, $this->app['config']->get('pharaonic.ratings.step'));
    $this->assertTrue($this->app['config']->get('pharaonic.ratings.cleanup_on_delete'));
});

test('the configuration is published to config pharaonic', function () {
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
});

test('the migrations are published to database migrations', function () {
    $source = realpath(__DIR__.'/../../database/migrations');

    foreach (['pharaonic', 'laravel-ratings', 'pharaonic-migrations', 'ratings-migrations'] as $tag) {
        $published = [];

        foreach (ServiceProvider::pathsToPublish(RatingsServiceProvider::class, $tag) as $from => $to) {
            $published[realpath($from) ?: $from] = $to;
        }

        $this->assertArrayHasKey($source, $published, $tag);
        $this->assertSame($this->app->databasePath('migrations'), $published[$source]);
    }
});
