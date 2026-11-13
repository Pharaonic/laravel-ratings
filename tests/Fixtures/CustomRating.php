<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Pharaonic\Laravel\Ratings\Models\Rating;

/**
 * An application's own rating model, on its own table, with its own logic.
 */
class CustomRating extends Rating
{
    /**
     * How many CustomRating rows were created, counted by its own model event.
     */
    public static int $created = 0;

    protected $table = 'reviews';

    protected static function boot()
    {
        parent::boot();

        static::created(function () {
            static::$created++;
        });
    }

    public function isPositive(): bool
    {
        return $this->value >= 4;
    }
}
