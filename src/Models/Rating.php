<?php

namespace Pharaonic\Laravel\Ratings\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Pharaonic\Laravel\Ratings\Support\RatingManager;
use Pharaonic\Laravel\Ratings\Support\RatingScale;
use UnexpectedValueException;

/**
 * A rating a rater model gave a rateable model: a value on the rateable's
 * scale and an optional comment.
 *
 * Every save checks a new or changed value against the rateable's scale, so
 * save() and update() follow the same rules as rate().
 *
 * @property int $id
 * @property string $rater_type
 * @property string $rater_id
 * @property string $rateable_type
 * @property string $rateable_id
 * @property int|float $value
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Rating extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'value',
        'comment',
    ];

    /**
     * Check the value against the rateable's scale before every save that sets or changes it.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (Rating $rating) {
            $rating->assertOnScale();
        });
    }

    /**
     * The table from the pharaonic.ratings.table setting, unless a subclass sets $table.
     *
     * @return string
     */
    public function getTable()
    {
        if ($this->table) {
            return $this->table;
        }

        $table = Config::get('pharaonic.ratings.table', 'ratings');

        return is_string($table) && $table !== '' ? $table : 'ratings';
    }

    /**
     * The model that gave the rating.
     */
    public function rater(): MorphTo
    {
        return $this->morphTo('rater', 'rater_type', 'rater_id');
    }

    /**
     * The model that is rated.
     */
    public function rateable(): MorphTo
    {
        return $this->morphTo('rateable', 'rateable_type', 'rateable_id');
    }

    /**
     * Only the ratings given by the rater.
     */
    public function scopeWhereRater(Builder $query, Model $rater): void
    {
        RatingManager::constrain($query, 'rater', $rater);
    }

    /**
     * Only the ratings of the rateable.
     */
    public function scopeWhereRateable(Builder $query, Model $rateable): void
    {
        RatingManager::constrain($query, 'rateable', $rateable);
    }

    /**
     * The value: an int when it is whole, a float otherwise. Null when the
     * query did not select the column.
     *
     * @throws UnexpectedValueException when the stored value is not a number
     */
    public function getValueAttribute(mixed $value): int|float|null
    {
        $value = RatingScale::fromDatabase($value);

        return $value === null ? null : RatingScale::toNumber($value);
    }

    /**
     * Set the value. A value equal to what the row holds keeps the stored
     * value as it is, so the attribute stays clean and saving it runs no UPDATE.
     *
     * @param  mixed  $value  an int, a float, or a decimal string, up to 4 decimal places
     *
     * @throws InvalidArgumentException when the value is not such a number
     */
    public function setValueAttribute(mixed $value): void
    {
        $value = RatingScale::normalize($value);
        $original = $this->original['value'] ?? null;

        try {
            $same = $original !== null && RatingScale::fromDatabase($original) === $value;
        } catch (UnexpectedValueException) {
            $same = false;
        }

        $this->attributes['value'] = $same ? $original : $value;
    }

    /**
     * Set the comment: a string, kept exactly as given, or null. A blank
     * string (empty, or only whitespace) means no comment and is stored as null.
     *
     * @param  mixed  $comment  a valid UTF-8 string of up to 65,535 bytes, or null
     *
     * @throws InvalidArgumentException when the comment is not such a string or null
     */
    public function setCommentAttribute(mixed $comment): void
    {
        $this->attributes['comment'] = RatingManager::comment($comment);
    }

    /**
     * Refuse to save a value that is missing or off the rateable's scale. A
     * save that changes neither the value nor the rateable checks nothing, so
     * a rating from before a scale change can still have its comment edited.
     *
     * @throws InvalidArgumentException when the value is missing or off the scale
     * @throws UnexpectedValueException when the rateable does not exist or its scale is not valid
     */
    protected function assertOnScale(): void
    {
        if ($this->exists && ! $this->isDirty(['value', 'rateable_type', 'rateable_id'])) {
            return;
        }

        $value = RatingScale::fromDatabase($this->attributes['value'] ?? null);

        if ($value === null) {
            throw new InvalidArgumentException('A rating needs a value.');
        }

        $rateable = RatingManager::rateableOf($this);

        RatingScale::for($rateable)->assertAllows($value, $rateable);
    }
}
