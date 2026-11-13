<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Uses neither trait: it can still be rated, on the configured scale.
 */
class Comment extends Model
{
    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
