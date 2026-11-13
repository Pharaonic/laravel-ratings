# Changelog

All notable changes to this project are documented here.

## 13.0.0 - 2026-11-13

First release of the Laravel 13 line, built from 12.0.0.

### Compatibility

- Laravel 13 on PHP 8.3, 8.4 or 8.5. PHP 8.2 is no longer supported on this line.
- The same API and behavior as 12.0.0.

## 12.0.0 - 2026-11-13

First release of the Laravel 12 line, built from 11.0.0.

### Compatibility

- Laravel 12 on PHP 8.2, 8.3, 8.4 or 8.5.
- The same API and behavior as 11.0.0.

## 11.0.0 - 2026-11-13

First release of the Laravel 11 line, built from 10.0.0.

### Compatibility

- Laravel 11 on PHP 8.2, 8.3 or 8.4. PHP 8.1 is no longer supported on this line.
- The same API and behavior as 10.0.0.

## 10.0.0 - 2026-11-13

First release of the Laravel 10 line, built from 9.0.0.

### Compatibility

- Laravel 10 on PHP 8.1, 8.2 or 8.3. PHP 8.0 is no longer supported on this line.
- The same API and behavior as 9.0.0.

## 9.0.0 - 2026-11-13

First release of the Laravel 9 line, built from 8.0.0.

### Compatibility

- Laravel 9.33+ on PHP 8.0, 8.1 or 8.2. Laravel 9.33 is the first 9.x release that requires a Carbon release (2.62.1+) working on PHP 8.2; with older ones, every date method throws a `TypeError` on PHP 8.2.
- The same API and behavior as 8.0.0.

## 8.0.0 - 2026-11-13

First release of the Laravel 8 line, built from 7.0.0.

### Compatibility

- Laravel 8.75+ on PHP 8.0 and 8.1.
- The same API and behavior as 7.0.0.

## 7.0.0 - 2026-11-13

First release of the Laravel 7 line, built from 6.0.0.

### Compatibility

- Laravel 7.30+ on PHP 8.0.
- The same API and behavior as 6.0.0.

## 6.0.0 - 2026-11-13

### Added

- Initial release: polymorphic ratings between any two Eloquent models, with `CanRate` for the model that rates and `HasRatings` for the model that is rated.
- One rating per rater and rateable pair, enforced by a unique index. `rate()` and `rateBy()` create or update it and return a `RatingResult`: `created`, `updated`, or `unchanged`, with the previous and current value.
- An optional comment on each rating. Without a comment argument, `rate()` keeps the stored comment (a new rating has none); a string sets it, and `null` clears it. A blank string is stored as `null`.
- Rating scales: 1 to 5 in whole steps by default, configurable, and per model through a `ratingScale()` method. Values may have up to 4 decimal places and are checked exactly, without float rounding errors. Every save of a new or changed value is checked, including `save()` and `update()` on the Rating model; changing a scale never rewrites stored ratings.
- Aggregates: `averageRating()` (rounded to 4 decimal places, `null` without ratings), `ratingsCount()`, `ratingsSum()`, `ratingDistribution()`, `ratingsWithComments()`, and the `whereRatedBy()` and `whereRatingAtLeast()` scopes, the latter by average rating.
- `RatingCreated`, `RatingUpdated`, and `RatingRemoved` events, dispatched only when `rate()`, `rateBy()`, `unrate()`, or `unrateBy()` changed a row. Direct writes to the Rating model dispatch Eloquent's own model events only.
- Ratings given and received are deleted with a model that is deleted or force deleted, and kept when it is soft deleted. Configurable with `cleanup_on_delete`.
