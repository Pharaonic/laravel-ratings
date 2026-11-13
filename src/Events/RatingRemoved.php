<?php

namespace Pharaonic\Laravel\Ratings\Events;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Models\Rating;

/**
 * Dispatched once each time unrate() or unrateBy() deletes a rating, right
 * after the write's transaction. $rating holds the deleted row's values.
 *
 * Not dispatched by the cleanup that runs when a model is deleted.
 */
final class RatingRemoved
{
    public function __construct(
        public Rating $rating,
        public Model $rater,
        public Model $rateable,
    ) {
    }
}
