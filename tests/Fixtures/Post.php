<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

/**
 * Is rated on the default scale.
 */
class Post extends Model
{
    use HasRatings;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
