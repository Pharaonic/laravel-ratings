## Queries and Aggregates

Every aggregate is one SQL query on the rated model's ratings; none of them loads the ratings into memory.

### On one model

```php
$product->ratingsCount();        // 4
$product->averageRating();       // 3.75, or null when there are no ratings
$product->ratingsSum();          // 15, or 0 when there are no ratings
$product->ratingDistribution();  // [2 => 1, 4 => 2, 5 => 1]
```

| Method | Returns | Without ratings |
| --- | --- | --- |
| `ratingsCount()` | `int` | `0` |
| `averageRating()` | `float`, rounded half up to 4 decimal places (`4.3333`) | `null` |
| `ratingsSum()` | `int` when whole, `float` otherwise | `0` |
| `ratingDistribution()` | `array` of value => count, by ascending value | `[]` |

The average is rounded to 4 decimal places, the precision ratings are stored with, so it is the same on every database. Round it further for display: `round($product->averageRating() ?? 0, 1)`.

`ratingDistribution()` comes from one grouped query and lists only the values that occur. Its keys are the actual values, so it follows any scale. PHP arrays cannot have float keys, so whole values are integer keys and the others decimal strings:

```php
$course->ratingDistribution(); // [0 => 1, '0.5' => 1, '4.5' => 2, 10 => 1]
```

To show every star with a zero for the missing ones, merge it with the scale:

```php
$counts = $product->ratingDistribution();
$scale = $product->ratingScale();

foreach (range($scale['max'], $scale['min'], -$scale['step']) as $stars) {
    echo $stars.' stars: '.($counts[$stars] ?? 0);
}
```

### Relations

`ratings()` (on `HasRatings`) and `givenRatings()` (on `CanRate`) are `MorphMany` relations, so everything Eloquent offers works with them:

```php
$product->ratings()->with('rater')->latest()->paginate(20);
$product->ratings()->where('value', '>=', 4)->count();
$user->givenRatings()->with('rateable')->get();

Product::with('ratings')->get();
Product::has('ratings')->get();
Product::whereHas('ratings', function ($query) {
    $query->where('value', 1);
})->get();
```

### Filter models

`whereRatedBy()` keeps the models a rater rated:

```php
Product::whereRatedBy($user)->latest()->paginate();
```

`whereRatingAtLeast()` keeps the models whose **average** rating is at least the given number, not the models that have one rating that high. Models without ratings never match:

```php
Product::whereRatingAtLeast(4)->paginate();     // average of 4 or more
Product::whereRatingAtLeast(4.5)->whereRatedBy($user)->get();
```

It compares the average rounded to 4 decimal places, as `averageRating()` returns it, so the two always agree. Both scopes combine with any other constraint and with pagination.

### Counts and averages for a list

`withCount()` adds a `ratings_count` to each model in the same query. On Laravel 6, `withCount()` also takes another aggregate through a custom column, which gives an average for each model without one query per model:

```php
use Illuminate\Support\Facades\DB;

$products = Product::withCount([
    'ratings',
    'ratings as ratings_avg_value' => function ($query) {
        $query->select(DB::raw('avg(value)'));
    },
])->get();

$products->first()->ratings_count;      // 2
$products->first()->ratings_avg_value;  // "4.50000000" (the database's own type)
```

The column comes back as your database returns it; cast and round it for display.

### Query the ratings

The `Pharaonic\Laravel\Ratings\Models\Rating` model has two scopes for the two sides:

```php
use Pharaonic\Laravel\Ratings\Models\Rating;

Rating::query()->whereRater($user)->get();
Rating::query()->whereRateable($product)->where('value', 5)->count();
```

An unsaved model matches nothing in every scope and relation.
