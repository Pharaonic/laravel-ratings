<?php

namespace Pharaonic\Laravel\Ratings\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Pharaonic\Laravel\Ratings\Models\Rating;
use Pharaonic\Laravel\Ratings\Support\RatingManager;
use Pharaonic\Laravel\Ratings\Support\RatingResult;

/**
 * For a model that rates other models, such as a user.
 */
trait CanRate
{
    /**
     * Delete the ratings the model gave when it is deleted (not soft deleted).
     */
    public static function bootCanRate(): void
    {
        static::deleted(function (Model $model) {
            RatingManager::cleanUp($model, 'rater');
        });
    }

    /**
     * The ratings this model gave.
     *
     * @return MorphMany<Rating, $this>
     */
    public function givenRatings(): MorphMany
    {
        return RatingManager::relation($this, 'rater');
    }

    /**
     * Rate the model: create the rating, or update this model's rating of it.
     *
     * Without a comment argument, an existing comment is kept (a new rating
     * has none). A string sets the comment, and null clears it.
     *
     * @param  int|float|string  $value  a value on the rateable's scale
     * @param  string|null  $comment
     *
     * @throws \InvalidArgumentException when a model is unsaved, or the value or comment is not valid
     * @throws \RuntimeException when a model event cancels the save
     */
    public function rate(Model $rateable, mixed $value, mixed $comment = null): RatingResult
    {
        return RatingManager::rate($this, $rateable, $value, func_num_args() > 2, $comment);
    }

    /**
     * The rating this model gave the model, or null.
     */
    public function ratingFor(Model $rateable): ?Rating
    {
        return RatingManager::of($this, $rateable);
    }

    /**
     * Whether this model rated the model.
     */
    public function hasRated(Model $rateable): bool
    {
        return RatingManager::has($this, $rateable);
    }

    /**
     * Remove the rating. True when this call removed it, false when there was none.
     *
     * @throws \InvalidArgumentException when a model is unsaved
     */
    public function unrate(Model $rateable): bool
    {
        return RatingManager::unrate($this, $rateable);
    }
}
