<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

/**
 * Rated from 0 to 10 in half steps.
 */
class Course extends Model
{
    use HasRatings;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @return array{min: int, max: int, step: float}
     */
    public function ratingScale(): array
    {
        return ['min' => 0, 'max' => 10, 'step' => 0.5];
    }
}
