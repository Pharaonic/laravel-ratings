<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

/**
 * Soft deletes, and is rated.
 */
class SoftDeletablePost extends Model
{
    use HasRatings;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
