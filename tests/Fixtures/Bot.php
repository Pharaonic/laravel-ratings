<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\CanRate;

/**
 * Rates other models; it is not a user.
 */
class Bot extends Model
{
    use CanRate;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
