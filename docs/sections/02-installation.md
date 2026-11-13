## Installation

Install the package with Composer and create its table.

### Requirements

- PHP 8.2, 8.3 or 8.4
- Laravel 11
- MySQL 5.7+, MariaDB 10.6+, PostgreSQL, or SQLite

### Composer Installation

```bash title="Terminal" no-line-numbers
composer require pharaonic/laravel-ratings
```

The service provider is registered automatically.

### Run Migrations

The package loads its migration automatically, so the `ratings` table is created with your other migrations:

```bash title="Terminal" no-line-numbers
php artisan migrate
```

To change the migration before it runs, publish it into `database/migrations`:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=ratings-migrations
```

The published file keeps the package's file name, `2026_11_13_000001_create_pharaonic_ratings_table.php`, so Laravel runs your copy instead of the package's, never both.

:::warning Existing ratings table
The migration stops with an error when the table already exists. Set `pharaonic.ratings.table` to another name before migrating, or move that table out of the way first.
:::

### Publish Configuration

Publish the configuration only when you need another table name, another default scale, or want to turn off cleanup on delete:

```bash title="Terminal" no-line-numbers
php artisan vendor:publish --tag=ratings-config
```

The file is published to `config/pharaonic/ratings.php`.

### Tested Databases

This release line is tested on PHP 8.2, 8.3 and 8.4 with Laravel 11 against:

| Database | Versions tested |
| --- | --- |
| SQLite | the versions bundled with PHP 8.2, 8.3 and 8.4 |
| MySQL | 5.7, 8.4 |
| MariaDB | 10.6 |
| PostgreSQL | 16 |

SQL Server is not tested.

:::success Installation Complete
You're all set! Add `CanRate` and `HasRatings` to your models, as [Basic Usage](#basic-usage) shows.
:::
