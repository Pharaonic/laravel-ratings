## Rating Scales

A scale is a `min`, a `max`, and a `step`: the allowed values are `min`, `min + step`, `min + 2 * step`, … up to `max`. Every rated model uses the configured scale, 1 to 5 in whole steps, unless it defines its own.

### A scale per model

Define `ratingScale()` on the rated model:

```php title="app/Models/Course.php"
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

class Course extends Model
{
    use HasRatings;

    public function ratingScale(): array
    {
        return ['min' => 0, 'max' => 10, 'step' => 0.5];
    }
}
```

```php
$user->rate($course, 8.5);   // allowed
$user->rate($course, 8.75);  // InvalidArgumentException: not on the rating scale of [App\Models\Course]: 0 to 10 in steps of 0.5
$user->rate($course, 11);    // InvalidArgumentException
```

`ratingScale()` is a regular method, so it can depend on the model's own attributes. On a model without your override it returns the configured scale, so you can always read it, for example to draw the right number of stars:

```php
$product->ratingScale(); // ['min' => 1, 'max' => 5, 'step' => 1]
```

A model that uses neither `HasRatings` nor its own `ratingScale()` can still be rated, on the configured scale.

### Exact decimals

Values and scales may have up to 4 decimal places and 6 whole digits (up to 999999.9999), the precision of the `DECIMAL(10,4)` column. They are compared as exact decimals, so float noise never refuses a valid rating:

```php
// On a 0 to 1 scale in steps of 0.1:
$user->rate($product, 0.1 + 0.2)->current(); // 0.3, although the float is 0.30000000000000004
```

A value with more decimal places (`4.55555`), out of range, not finite (`NAN`, `INF`), or not a plain decimal (`'4,5'`, `'1e3'`, `' 4'`, `true`, `null`) throws an `InvalidArgumentException` before anything is written.

### Direct saves follow the scale

`$rating->save()` and `$rating->update()` check a new or changed value against the rated model's scale too. To find the scale, the rating loads the rated model when it is not already loaded, through your morph map and without global scopes, so a soft-deleted model is found. Load the relation first when you update many ratings:

```php
use Pharaonic\Laravel\Ratings\Models\Rating;

Rating::with('rateable')->whereIn('id', $ids)->get()->each->update(['value' => 5]);
```

A rating whose rated model no longer exists cannot change its value: the save throws an `UnexpectedValueException` that says so, rather than guessing a scale. Its comment can still be edited, and it can be deleted.

:::warning Query updates skip the checks
Laravel runs no model code for query updates such as `Rating::query()->update([...])` or raw SQL, so neither the scale check nor the comment rules apply there. Use them only with values you know are valid.
:::

### Changing a scale

Changing a scale never rewrites stored ratings: they keep their values and still count in every average. A stored value only meets the new scale when it is written again:

- a save that changes only the comment does not check the value, so old ratings stay editable;
- `rate()` with the old, now invalid value is refused, and so is a save that sets another invalid value;
- a valid new value is accepted as usual.

To move existing ratings to a new scale, convert them in a migration of your own, for example from 1–5 to 0–10 with `value * 2`, before or together with the scale change.
