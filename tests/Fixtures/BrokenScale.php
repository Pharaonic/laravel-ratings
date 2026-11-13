<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

/**
 * Defines a scale whose max is below its min.
 */
class BrokenScale extends Model
{
    use HasRatings;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * @return array{min: int, max: int, step: int}
     */
    public function ratingScale(): array
    {
        return ['min' => 5, 'max' => 1, 'step' => 1];
    }
}
