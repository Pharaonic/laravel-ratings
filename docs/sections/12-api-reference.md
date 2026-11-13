## API Reference

### CanRate

For the model that gives ratings.

| Method | Description | Returns |
| --- | --- | --- |
| `rate(Model $rateable, $value, $comment)` | Create or update this model's rating of `$rateable`. Omit `$comment` to keep the stored one; `null` clears it. | `RatingResult` |
| `ratingFor(Model $rateable)` | This model's rating of `$rateable`. | `Rating` or `null` |
| `hasRated(Model $rateable)` | Whether this model rated `$rateable`. | `bool` |
| `unrate(Model $rateable)` | Remove the rating. `true` when this call removed it. | `bool` |
| `givenRatings()` | The ratings this model gave. | `MorphMany` |

### HasRatings

For the model that receives ratings.

| Method | Description | Returns |
| --- | --- | --- |
| `rateBy(Model $rater, $value, $comment)` | The same as `$rater->rate($this, $value, $comment)`. | `RatingResult` |
| `ratingFrom(Model $rater)` | The rater's rating of this model. | `Rating` or `null` |
| `ratedBy(Model $rater)` | Whether the rater rated this model. | `bool` |
| `unrateBy(Model $rater)` | Remove the rater's rating. | `bool` |
| `ratings()` | The ratings this model received. | `MorphMany` |
| `ratingsWithComments()` | `ratings()` limited to ratings with a comment. | `MorphMany` |
| `ratingScale()` | The scale of this model; override it for a scale of its own. | `array` with `min`, `max`, `step` |
| `averageRating()` | The average, rounded to 4 decimal places. | `float` or `null` |
| `ratingsCount()` | How many ratings. | `int` |
| `ratingsSum()` | The sum of the values. | `int` or `float` |
| `ratingDistribution()` | How many ratings of each value, by ascending value. | `array` |

| Scope | Description |
| --- | --- |
| `whereRatedBy(Model $rater)` | Only the models the rater rated. |
| `whereRatingAtLeast($average)` | Only the models whose average rating is at least `$average`. |

### RatingResult

`Pharaonic\Laravel\Ratings\Support\RatingResult`, returned by `rate()` and `rateBy()`.

| Method | Description | Returns |
| --- | --- | --- |
| `created()` | The call inserted the rating. | `bool` |
| `updated()` | The call changed the value or the comment. | `bool` |
| `unchanged()` | The rating already held this value and comment. | `bool` |
| `changed()` | `created()` or `updated()`. | `bool` |
| `status()` | `'created'`, `'updated'`, or `'unchanged'`. | `string` |
| `previous()` | The value before the call; `null` when it created the rating. | `int`, `float`, or `null` |
| `current()` | The value after the call. | `int` or `float` |
| `rating()` | The stored rating. | `Rating` |

### Rating model

`Pharaonic\Laravel\Ratings\Models\Rating`

| Member | Description |
| --- | --- |
| `value` | The value: `int` when whole, `float` otherwise. Checked against the scale on save. |
| `comment` | The comment, or `null`. |
| `rater_type`, `rater_id` | The rater's morph type and id. |
| `rateable_type`, `rateable_id` | The rated model's morph type and id. |
| `rater()` | `MorphTo` the model that gave the rating. |
| `rateable()` | `MorphTo` the rated model. |
| `whereRater(Model $rater)` | Scope: ratings given by the rater. |
| `whereRateable(Model $rateable)` | Scope: ratings of the rated model. |

`value` and `comment` are fillable.

### Events

| Event | Properties |
| --- | --- |
| `RatingCreated` | `$rating`, `$rater`, `$rateable` |
| `RatingUpdated` | `$rating`, `$rater`, `$rateable`, `$previousValue`, `$previousComment` |
| `RatingRemoved` | `$rating`, `$rater`, `$rateable` |

All in `Pharaonic\Laravel\Ratings\Events`.

### Exceptions

| Exception | When |
| --- | --- |
| `InvalidArgumentException` | An unsaved model in a write, a value that is not a number or not on the scale, an invalid comment, an invalid minimum average. |
| `UnexpectedValueException` | An invalid scale, or a direct save of a rating whose rated model no longer exists. |
| `RuntimeException` | A model event cancelled the save, or the rating kept being deleted while it was written. |
