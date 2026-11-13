<p align="center"><a href="https://pharaonic.dev/packages/laravel/ratings" target="_blank"><img src="https://raw.githubusercontent.com/Pharaonic/laravel-ratings/develop/docs/cover.webp"></a></p>

<p align="center">
  <a href="https://php.net" target="_blank"><img src="https://img.shields.io/static/v1?label=PHP&message=8.0%20|%208.1&color=blue&style=flat-square" alt="PHP Version : 8.0 | 8.1"></a>
  <a href="https://laravel.com" target="_blank"><img src="https://img.shields.io/static/v1?label=Laravel&message=8.x&color=F05340&style=flat-square" alt="Laravel Version : 8.x"></a>
  <img src="https://img.shields.io/static/v1?label=License&message=MIT&color=brightgreen&style=flat-square" alt="License">
  <img src="https://github.com/Pharaonic/laravel-ratings/actions/workflows/build.yml/badge.svg?branch=8.x" alt="Build">
  <br>
  <a href="https://packagist.org/packages/Pharaonic/laravel-ratings" target="_blank"><img src="https://img.shields.io/static/v1?label=Packagist&message=pharaonic/laravel-ratings&color=blue&logo=packagist&logoColor=white" alt="Source"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-ratings" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-ratings/v" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/pharaonic/laravel-ratings" target="_blank"><img src="https://poser.pugx.org/pharaonic/laravel-ratings/downloads" alt="Packagist Downloads"></a>
</p>

<h3 align="center">Polymorphic ratings for Eloquent models: one rating per rater, with a value on a scale and an optional comment.</h3>
<br>

## Installation

```bash
composer require pharaonic/laravel-ratings
php artisan migrate
```

The service provider is registered automatically, and `migrate` creates the `ratings` table. Publish the migration only to change it (`--tag=ratings-migrations`), and the configuration only to rename the table, change the default 1 to 5 scale, or turn off cleanup on delete:

```bash
php artisan vendor:publish --tag=ratings-config
```

## Usage

Add `CanRate` to the model that rates (it does not have to be a user) and `HasRatings` to the model that is rated. A model can use both.

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Pharaonic\Laravel\Ratings\Concerns\CanRate;

class User extends Authenticatable
{
    use CanRate;
}
```

```php
use Illuminate\Database\Eloquent\Model;
use Pharaonic\Laravel\Ratings\Concerns\HasRatings;

class Product extends Model
{
    use HasRatings;
}
```

```php
$result = $user->rate($product, 4, 'Nice product'); // creates the rating
$result->created();   // true
$result->current();   // 4

$result = $user->rate($product, 5);          // updates it, and keeps 'Nice product'
$result->updated();   // true
$result->previous();  // 4

$user->rate($product, 5)->unchanged();       // true: nothing to write
$user->rate($product, 5, null);              // clears the comment

$rating = $user->ratingFor($product);        // the Rating model, or null
$rating->update(['comment' => 'Updated review']);

$product->averageRating();       // 5.0, or null without ratings
$product->ratingsCount();        // 1
$product->ratingDistribution();  // [5 => 1]

Product::whereRatingAtLeast(4)->paginate(); // by average rating
```

## Documentation

Full documentation: [Laravel Ratings Documentation](https://pharaonic.dev/packages/laravel/ratings).

## Support & Security

See [SUPPORT.md](SUPPORT.md) and [SECURITY.md](SECURITY.md). Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT. See [LICENSE](LICENSE).
