---
view: components.packages.features
variant: compact
badge: Key Features
title: Everything a rating system needs
subtitle: Two traits and one migration, with the rules enforced for you.
items:
  - icon: badge-check
    title: One Rating Per Rater
    text: A unique index keeps each rater and model to one rating, even under simultaneous requests.
  - icon: switch
    title: Created, Updated, or Unchanged
    text: "`rate()` returns a `RatingResult` with the status and the value before and after."
  - icon: pencil
    title: Optional Comments
    text: Omit the comment to keep it, pass a string to replace it, or `null` to clear it.
  - icon: scale
    title: Exact Scales
    text: One to five by default, or any range and step per model, such as 0 to 10 in half steps.
  - icon: chart
    title: SQL Aggregates
    text: "`averageRating()`, `ratingsCount()`, `ratingDistribution()` and `whereRatingAtLeast()`, without loading ratings."
  - icon: database
    title: Any Model, Any Key
    text: Integer, UUID and ULID keys, morph maps, soft deletes and native Eloquent relations.
---
