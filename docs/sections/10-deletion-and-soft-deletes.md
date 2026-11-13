## Deletes and Soft Deletes

When a model that uses `CanRate` or `HasRatings` is deleted, the ratings it gave or received are deleted with it. A model with both traits loses both.

```php
$product->delete(); // its ratings are gone
$user->delete();    // the ratings it gave are gone
```

### Soft deletes

A soft delete keeps every rating, so restoring the model brings its ratings back. A force delete removes them:

```php
$post->delete();       // soft delete: ratings kept
$post->restore();      // the same ratings, average included
$post->forceDelete();  // ratings deleted
```

### Turning cleanup off

Set `cleanup_on_delete` to `false` to keep the ratings of deleted models, for example when you archive them yourself:

```php title="config/pharaonic/ratings.php"
'cleanup_on_delete' => false,
```

### What the cleanup does not cover

- It is a bulk delete: no `RatingRemoved` event and no `Rating` model events.
- It runs from the deleted model's `deleted` event, so Laravel's query deletes (`Product::query()->delete()`), raw SQL, and database cascades skip it, and leave ratings that point at missing rows.

To find such orphaned ratings later, check them against their table in chunks:

```php
use App\Product;
use Pharaonic\Laravel\Ratings\Models\Rating;

Rating::query()
    ->where('rateable_type', (new Product)->getMorphClass())
    ->chunkById(500, function ($ratings) {
        $existing = Product::query()
            ->whereIn('id', $ratings->pluck('rateable_id')->all())
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $orphans = $ratings->reject(fn ($rating) => in_array($rating->rateable_id, $existing, true));

        Rating::query()->whereKey($orphans->modelKeys())->delete();
    });
```

Run it from a command or a scheduled job of your own; the package schedules nothing.
