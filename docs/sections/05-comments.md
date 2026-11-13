## Comments

Each rating has one optional comment, stored with it. It is part of the rating, not a separate review.

### Leave it out, set it, or clear it

`rate()` tells an omitted comment apart from an explicit `null`:

| Call | No rating yet | Existing rating |
| --- | --- | --- |
| `rate($product, 5)` | Creates it with no comment | Changes the value; **keeps** the comment |
| `rate($product, 4, 'Nice')` | Creates it with the comment | Changes the value and the comment |
| `rate($product, 4, null)` | Creates it with no comment | Changes the value; **clears** the comment |
| `rate($product, $sameValue)` | Creates it | `unchanged`: nothing is written |
| `rate($product, $sameValue, 'Different')` | Creates it | `updated`: only the comment changed |

```php
$user->rate($product, 4, 'Nice');
$user->rate($product, 5);                  // value 5, comment still 'Nice'
$user->rate($product, 5, comment: null);   // value 5, no comment
```

`rateBy()` follows the same rules. Named arguments are fine: `comment: null` clears the comment just like a third positional `null`.

### What a comment may be

- A string, stored exactly as you pass it, spaces and line breaks included.
- A blank string (empty, or only spaces, tabs, and line breaks) means no comment and is stored as `null`, so a form's empty textarea clears it.
- `null`, which clears it.

Anything else (an integer, an array, `true`) throws an `InvalidArgumentException`, and so does a string that is not valid UTF-8 or is longer than 65,535 bytes, the size of a MySQL `TEXT` column. The same rules apply when you set `$rating->comment` directly.

### Ratings with a comment

`ratingsWithComments()` is the `ratings()` relation limited to ratings that have a comment. Because blank comments are stored as `null`, it never returns an empty one:

```php
$reviews = $product->ratingsWithComments()
    ->with('rater')
    ->latest()
    ->paginate(10);
```
