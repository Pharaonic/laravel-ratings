<?php

namespace Pharaonic\Laravel\Ratings\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingManager;
use Pharaonic\Laravel\Ratings\Support\RatingResult;
use Pharaonic\Laravel\Ratings\Support\RatingScale;

/**
 * For a model that other models rate, such as a product.
 */
trait HasRatings
{
    /**
     * Delete the ratings the model received when it is deleted (not soft deleted).
     */
    public static function bootHasRatings(): void
    {
        static::deleted(function (Model $model) {
            RatingManager::cleanUp($model, 'rateable');
        });
    }

    /**
     * The ratings this model received.
     *
     * @return MorphMany<Rating, $this>
     */
    public function ratings(): MorphMany
    {
        return RatingManager::relation($this, 'rateable');
    }

    /**
     * The values a rating of this model may take: from min to max, in steps
     * of step. The pharaonic.ratings settings by default; define this method
     * on the model to give it its own scale.
     *
     * @return array{min: int|float|string, max: int|float|string, step: int|float|string}
     */
    public function ratingScale(): array
    {
        return RatingScale::configured()->toArray();
    }

    /**
     * Rate this model as the rater, the same as $rater->rate($this, $value, $comment).
     *
     * Without a comment argument, an existing comment is kept (a new rating
     * has none). A string sets the comment, and null clears it.
     *
     * @param  int|float|string  $value  a value on this model's scale
     * @param  string|null  $comment
     *
     * @throws \InvalidArgumentException when a model is unsaved, or the value or comment is not valid
     * @throws \RuntimeException when a model event cancels the save
     */
    public function rateBy(Model $rater, mixed $value, mixed $comment = null): RatingResult
    {
        return RatingManager::rate($rater, $this, $value, func_num_args() > 2, $comment);
    }

    /**
     * Remove the rater's rating. True when this call removed it.
     *
     * @throws \InvalidArgumentException when a model is unsaved
     */
    public function unrateBy(Model $rater): bool
    {
        return RatingManager::unrate($rater, $this);
    }

    /**
     * Whether the rater rated this model.
     */
    public function ratedBy(Model $rater): bool
    {
        return RatingManager::has($rater, $this);
    }

    /**
     * The rating the rater gave this model, or null.
     */
    public function ratingFrom(Model $rater): ?Rating
    {
        return RatingManager::of($rater, $this);
    }

    /**
     * The average rating, rounded to 4 decimal places, or null when there are no ratings.
     */
    public function averageRating(): ?float
    {
        return RatingManager::average($this);
    }

    /**
     * How many ratings this model received.
     */
    public function ratingsCount(): int
    {
        return $this->ratings()->toBase()->count();
    }

    /**
     * The sum of the rating values: an int when it is whole, a float otherwise; 0 without ratings.
     */
    public function ratingsSum(): int|float
    {
        return RatingManager::sum($this);
    }

    /**
     * How many ratings of each value this model received, by ascending value.
     * Only values that occur are listed. PHP arrays cannot have float keys, so
     * whole values are int keys and the others decimal strings: [4 => 2, '4.5' => 1, 5 => 7].
     *
     * @return array<int|string, int>
     */
    public function ratingDistribution(): array
    {
        return RatingManager::distribution($this);
    }

    /**
     * The ratings that have a comment. A comment is never blank: a blank
     * string is stored as null.
     *
     * @return MorphMany<Rating, $this>
     */
    public function ratingsWithComments(): MorphMany
    {
        $ratings = $this->ratings();
        $ratings->getQuery()->whereNotNull($ratings->getRelated()->qualifyColumn('comment'));

        return $ratings;
    }

    /**
     * Only the models the rater rated.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereRatedBy(Builder $query, Model $rater): void
    {
        $query->whereHas('ratings', function (Builder $ratings) use ($rater) {
            RatingManager::constrain($ratings, 'rater', $rater);
        });
    }

    /**
     * Only the models whose average rating is at least $average (rounded to 4
     * decimal places, as averageRating() returns it). Models without ratings never match.
     *
     * @param  Builder<static>  $query
     * @param  int|float|string  $average
     *
     * @throws \InvalidArgumentException when $average is not a number with up to 4 decimal places
     */
    public function scopeWhereRatingAtLeast(Builder $query, mixed $average): void
    {
        RatingManager::whereAverageAtLeast($query, $this, $average);
    }
}
