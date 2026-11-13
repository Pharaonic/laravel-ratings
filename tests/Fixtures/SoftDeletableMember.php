<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pharaonic\Laravel\Ratings\Concerns\CanRate;

/**
 * Soft deletes, and rates.
 */
class SoftDeletableMember extends Model
{
    use CanRate;
    use SoftDeletes;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
