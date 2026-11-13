---
view: components.packages.quick-look
title: A quick look
subtitle: Rate, read, and summarize in a few lines.
file: app/Http/Controllers/ProductRatingController.php
language: php
code: |
  $result = $user->rate($product, 4, 'Nice product');
  $result->created();             // true

  $result = $user->rate($product, 5); // keeps the comment
  $result->previous();            // 4

  $user->ratingFor($product)->update(['comment' => 'Even better']);

  $product->averageRating();      // 5.0
  $product->ratingDistribution(); // [5 => 1]
  Product::whereRatingAtLeast(4)->paginate();
---
