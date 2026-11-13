## Events

The package dispatches three events, only when a call actually changed the table:

| Event | Dispatched by | Not dispatched |
| --- | --- | --- |
| `Pharaonic\Laravel\Ratings\Events\RatingCreated` | `rate()`, `rateBy()` when they insert the rating | When the rating already existed |
| `Pharaonic\Laravel\Ratings\Events\RatingUpdated` | `rate()`, `rateBy()` when they change the value or the comment | When nothing changed; for a direct `$rating->save()` or `update()` |
| `Pharaonic\Laravel\Ratings\Events\RatingRemoved` | `unrate()`, `unrateBy()` when they delete the rating | When there was none, and for the cleanup on delete |

Each has the public properties `$rating`, `$rater`, and `$rateable`. `RatingUpdated` also has `$previousValue` and `$previousComment`, what the rating held before the call, so a listener can adjust a stored total without reading the table again. In `RatingRemoved`, `$rating` is the deleted row, with `exists` false.

```php title="app/Listeners/UpdateProductScore.php"
namespace App\Listeners;

use Pharaonic\Laravel\Ratings\Events\RatingUpdated;

class UpdateProductScore
{
    public function handle(RatingUpdated $event)
    {
        $event->rateable->increment('score_total', $event->rating->value - $event->previousValue);
    }
}
```

```php title="app/Providers/EventServiceProvider.php"
protected $listen = [
    \Pharaonic\Laravel\Ratings\Events\RatingUpdated::class => [
        \App\Listeners\UpdateProductScore::class,
    ],
];
```

### When listeners run

Each write runs in a short transaction of its own, and the event is dispatched right after it commits. When the database reports a deadlock, the write is retried before any event, so a retried write dispatches once.

When you rate inside your own database transaction, the package's transaction is a savepoint in yours, so listeners run before your transaction commits, and a rollback afterwards does not take the event back. Make a listener that must only see committed ratings a queued listener dispatched after your transaction, or call the package outside it.

### Model events

Inserts and updates go through Eloquent's `save()`, so the `Rating` model's own `saving`, `creating`, `created`, `updating`, `updated`, and `saved` events fire. A call that changes nothing fires none of them.

- A listener that returns `false` from `saving`, `creating`, or `updating` cancels the write. `rate()` then throws a `RuntimeException` ("A model event cancelled saving the rating…") instead of returning a result for a rating that isn't stored, and no package event is dispatched.
- Direct `update()` and `save()` calls fire these model events only, not the package events.

The removal deletes the row with a query, so the `Rating` model's `deleting` and `deleted` events do not fire. Listen to `RatingRemoved` instead.
