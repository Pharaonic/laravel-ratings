<?php

namespace Pharaonic\Laravel\Ratings\Support;

use Pharaonic\Laravel\Ratings\Models\Rating;

/**
 * What one rate() or rateBy() call did: created the rating, updated it, or
 * found it unchanged, with the value before and after the call.
 *
 * The result is a snapshot of that call: its status and values never change,
 * even when the rating model is changed or saved again later.
 */
final class RatingResult
{
    /**
     * @param  'created'|'updated'|'unchanged'  $status
     *
     * @internal
     */
    public function __construct(
        private string $status,
        private Rating $rating,
        private int|float|null $previous,
        private int|float $current,
    ) {
    }

    /**
     * Whether the call inserted the rating.
     */
    public function created(): bool
    {
        return $this->status === 'created';
    }

    /**
     * Whether the call changed the value or the comment of an existing rating.
     */
    public function updated(): bool
    {
        return $this->status === 'updated';
    }

    /**
     * Whether the rating already held this value and comment, so the call wrote nothing.
     */
    public function unchanged(): bool
    {
        return $this->status === 'unchanged';
    }

    /**
     * Whether the call wrote to the database: created or updated.
     */
    public function changed(): bool
    {
        return $this->status !== 'unchanged';
    }

    /**
     * @return 'created'|'updated'|'unchanged'
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * The value the rating held before the call, or null when the call created it.
     */
    public function previous(): int|float|null
    {
        return $this->previous;
    }

    /**
     * The value the rating holds after the call.
     */
    public function current(): int|float
    {
        return $this->current;
    }

    /**
     * The stored rating.
     */
    public function rating(): Rating
    {
        return $this->rating;
    }
}
