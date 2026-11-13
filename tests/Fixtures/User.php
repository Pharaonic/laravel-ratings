<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Ratings\Concerns\CanRate;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

/**
 * Rates other models and is rated too.
 */
class User extends Authenticatable
{
    use CanRate;
    use HasRatings;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
