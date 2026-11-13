:::badges
- Laravel Package {color=blue}
- {release.label} {color=green}
- {package.license} License {color=purple}
:::

# Ratings

Ratings lets any Eloquent model rate any other: a user rates a product, a customer rates a course, a bot scores a comment. Each rater has one rating per model, with a value on a scale and an optional comment. `rate()` creates or updates it and tells you which one happened, and the aggregates you need for a product page (average, count, sum, distribution) are single SQL queries.

:::features
### One Rating Per Rater {icon="badge-check"}
A unique index keeps each rater and rated model to one row, even when requests arrive at the same moment.

### Create or Update in One Call {icon="switch"}
rate() inserts the rating or changes it, and returns whether it was created, updated, or unchanged, with the value before and after.

### Optional Comments {icon="pencil"}
Leave the comment out to keep the one already stored, pass a string to replace it, or pass null to clear it.

### Exact Scales {icon="scale"}
One to five stars by default, any range and step per model, such as 0 to 10 in half steps, checked without float rounding errors.

### Aggregates in SQL {icon="chart"}
Average, count, sum, and distribution of a model's ratings come from the database, never from loading every rating into memory.

### Any Model, Any Key {icon="database"}
Integer, UUID, and ULID keys and morph map aliases work on both sides, through native Eloquent relations.
:::

:::info Quick Tip
The rating is a regular Eloquent model. Get it with `ratingFor()` and change it with `update()` or `save()`: the same scale and comment rules apply.
:::
