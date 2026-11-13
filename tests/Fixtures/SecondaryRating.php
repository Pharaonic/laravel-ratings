<?php

namespace Pharaonic\Laravel\Ratings\Tests\Fixtures;

use Pharaonic\Laravel\Ratings\Models\Rating;

/**
 * A rating model on a connection of its own.
 */
class SecondaryRating extends Rating
{
    protected $connection = 'secondary';
}
