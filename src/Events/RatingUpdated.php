<?php

namespace Pharaonic\Laravel\Ratings\Events;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Models\Rating;

/**
 * Dispatched once each time rate() or rateBy() changes the value or the
 * comment of a rating, right after the write's transaction. Never for a call
 * that changed nothing.
 *
 * $previousValue and $previousComment are what the rating held before the call.
 */
final class RatingUpdated
{
    public function __construct(
        public Rating $rating,
        public Model $rater,
        public Model $rateable,
        public int|float $previousValue,
        public ?string $previousComment,
    ) {
    }
}
