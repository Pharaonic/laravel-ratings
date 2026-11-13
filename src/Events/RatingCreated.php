<?php

namespace Pharaonic\Laravel\Ratings\Events;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Models\Rating;

/**
 * Dispatched once each time rate() or rateBy() inserts a rating, right after
 * the write's transaction.
 */
final class RatingCreated
{
    public function __construct(
        public Rating $rating,
        public Model $rater,
        public Model $rateable,
    ) {
    }
}
