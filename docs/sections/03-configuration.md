## Configuration

Every setting has a default, so the package works without a configuration file. Publish it with `php artisan vendor:publish --tag=ratings-config` to change one; it lands in `config/pharaonic/ratings.php`.

```php title="config/pharaonic/ratings.php"
return [
    'table' => 'ratings',

    'min' => 1,
    'max' => 5,
    'step' => 1,

    'cleanup_on_delete' => true,
];
```

| Key | Default | Description |
| --- | --- | --- |
| `table` | `'ratings'` | The table the migration creates and the package reads and writes. |
| `min` | `1` | The lowest value a rating may take. |
| `max` | `5` | The highest value a rating may take. |
| `step` | `1` | The distance between two allowed values: `min`, `min + step`, `min + 2 * step`, … up to `max`. |
| `cleanup_on_delete` | `true` | Delete the ratings a model gave or received when it is deleted (or force deleted). |

### The table name

Set `table` before the first `php artisan migrate`. Changing it afterwards does not rename the existing table: rename it with a migration of your own, then change the setting.

### The default scale

`min`, `max`, and `step` are the scale of every rated model that does not define its own. They may be integers, floats, or decimal strings with up to 4 decimal places:

```php title="config/pharaonic/ratings.php"
'min' => 0,
'max' => 10,
'step' => '0.5',
```

`step` must be greater than 0, `max` greater than `min`, and `max - min` a whole number of steps, so that `max` is itself a valid rating. A scale that breaks these rules throws an `UnexpectedValueException` the first time it is used. See [Rating Scales](#rating-scales) for per-model scales and what happens to stored ratings when a scale changes.
