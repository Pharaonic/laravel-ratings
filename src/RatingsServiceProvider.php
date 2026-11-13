<?php

namespace Pharaonic\Laravel\Ratings;

use Illuminate\Support\ServiceProvider;

final class RatingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ratings.php', 'pharaonic.ratings');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ratings.php' => $this->app->configPath('pharaonic/ratings.php'),
            ], ['pharaonic', 'laravel-ratings', 'pharaonic-config', 'ratings-config']);

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], ['pharaonic', 'laravel-ratings', 'pharaonic-migrations', 'ratings-migrations']);
        }
    }
}
