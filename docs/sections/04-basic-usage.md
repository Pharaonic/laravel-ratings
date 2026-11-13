## Basic Usage

Add `CanRate` to the model that gives ratings and `HasRatings` to the model that receives them. The rater does not have to be a user, and a model can use both traits.

```php title="app/User.php"
namespace App;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Ratings\Concerns\CanRate;

class User extends Authenticatable
{
    use CanRate;
}
```

```php title="app/Product.php"
namespace App;

use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

class Product extends Model
{
    use HasRatings;
}
```

### Rate a model

`rate()` creates the rating, or updates the rating this user already gave. The value must be on the product's scale (1 to 5 by default):

```php
$result = $user->rate($product, 5);
$result = $user->rate($product, 4, 'Nice product'); // with a comment
```

`rateBy()` is the same call from the other side, with the same result:

```php
$result = $product->rateBy($user, 4, 'Nice product');
```

Both models must be saved. An unsaved one throws an `InvalidArgumentException`, and nothing is written.

### The result

`rate()` returns a `RatingResult` that says what the call did:

```php
$result = $user->rate($product, 5);   // the first rating
$result->created();    // true
$result->status();     // 'created'
$result->previous();   // null
$result->current();    // 5

$result = $user->rate($product, 3);   // a different value
$result->updated();    // true
$result->previous();   // 5
$result->current();    // 3

$result = $user->rate($product, 3);   // the same value again
$result->unchanged();  // true: no query wrote anything
$result->changed();    // false

$result->rating();     // the Rating model in every case
```

`changed()` is true for created and updated. The result is a snapshot of that call: saving the rating again later does not change it. A write that a model event cancels is never reported: it throws a `RuntimeException` instead.

### Values

A value is an integer, a float, or a decimal string, with up to 4 decimal places. Ratings come back as an `int` when they are whole and a `float` otherwise:

```php
$user->rate($product, '4')->current();   // 4
$user->rate($course, 4.5)->current();    // 4.5 (on a scale with half steps)
$user->rate($product, 4.0)->unchanged(); // true: 4.0 is the value already stored
```

### Read a rating

```php
$user->hasRated($product);     // true
$product->ratedBy($user);      // true

$rating = $user->ratingFor($product);  // the Rating model, or null
$rating = $product->ratingFrom($user); // the same

$rating->value;    // 3
$rating->comment;  // 'Nice product'
$rating->rater;    // the User
$rating->rateable; // the Product
```

`ratingFor()` and `ratingFrom()` only read; they never create a rating. For an unsaved model, they return `null` and `hasRated()` returns `false`.

### Edit a rating with Eloquent

The rating is a regular Eloquent model, so plain `update()` and `save()` change it. The same rules apply as for `rate()`: the value must be on the rated model's scale, and a blank comment is stored as `null`.

```php
$rating = $user->ratingFor($product);

$rating->update(['comment' => 'Updated review']);
$rating->update(['value' => 4, 'comment' => 'Changed my mind']);

$rating->comment = null;
$rating->save();
```

`value` and `comment` are the fillable attributes. The package has no method for each field on purpose: `update()` already does it.

### Remove a rating

```php
$user->unrate($product);     // true: this call removed it
$user->unrate($product);     // false: there was none
$product->unrateBy($user);   // the same, from the other side
```
