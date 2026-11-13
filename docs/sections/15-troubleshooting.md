## Troubleshooting

### "The rating [x] is not on the rating scale of [App\Models\Product]"

The value is outside the model's scale or between two steps. Check `$product->ratingScale()`, and validate requests against it before calling `rate()`.

### "max - min must be a whole number of steps"

The scale cannot reach `max` from `min` in whole steps, such as 1 to 5 in steps of 0.3. Pick a step that divides `max - min`.

### "has more than 4 decimal places"

Values are stored with 4 decimal places. Round the value before rating, for example `round($value, 4)`, or use a coarser scale.

### "The [ratings] table already exists"

The migration never runs over an existing table. Set `pharaonic.ratings.table` to another name before `php artisan migrate`, or move the old table away.

### "The rateable [App\Models\Course] [5] of the rating does not exist"

A direct save changed the value of a rating whose rated model was deleted without the cleanup, for example by a query delete. Delete the orphaned rating, as [Deletes and Soft Deletes](#deletion-and-soft-deletes) shows.

### "A model event cancelled saving the rating"

A `saving`, `creating`, or `updating` listener or observer on the `Rating` model returned `false`. The rating was not written.

### The comment disappears when I change the value

A third argument of `null`, or a blank string, clears the comment. Call `rate($model, $value)` with two arguments to keep it, or build the arguments so the comment is only passed when the user sent one.

### An average from withCount() looks like "4.50000000"

A custom `withCount()` column comes back as the database returns it. Cast and round it, or use `averageRating()` for one model.

### Old ratings fall outside a new scale

Changing a scale does not rewrite stored ratings. Convert them in a migration, as [Rating Scales](#rating-scales) explains.
