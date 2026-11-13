## Morph Maps and Ids

The `ratings` table stores each side as a morph type and an id, so any two models can rate each other.

### Morph maps

The type is what `getMorphClass()` returns: the class name, or its alias when you register a morph map. Register the aliases before rating anything, or existing rows keep the class names:

```php title="app/Providers/AppServiceProvider.php"
use Illuminate\Database\Eloquent\Relations\Relation;

public function boot()
{
    Relation::morphMap([
        'user' => \App\User::class,
        'product' => \App\Product::class,
    ]);
}
```

The type column holds up to 191 characters. A longer class name throws an `InvalidArgumentException` that suggests a morph map alias.

### Integer, UUID, and ULID keys

Ids are stored as strings of up to 64 characters, so integer, UUID, and ULID keys share one table, on either side. Relations, eager loading, `whereHas()`, `withCount()`, and both scopes compare the keys as strings, which PostgreSQL requires for integer and UUID columns.

### The same id, another type

A rating is identified by both types and both ids. `User` 1 and `Bot` 1 are different raters, and `Post` 1 and `Product` 1 are different rated models, so each pair has its own rating.
