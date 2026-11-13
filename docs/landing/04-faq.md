---
view: components.home.faq
badge: FAQ
title: "{package.name}"
highlight: Questions
subtitle: "Quick answers about installing and using {package.name}."
---

## What is {package.name}?

{card.description} It's a free, open-source {technology.name} package by Pharaonic.

## How do I install {package.name}?

Run `composer require {package.composer}` in your project's root directory, then `php artisan migrate`.

## What does {package.name} require?

The latest release requires {package.requiresText}.

## Can a user rate the same model twice?

No. Each rater has one rating per model; rating again updates it, and `rate()` tells you whether it was created, updated, or unchanged.

## Can I use half stars or a 0 to 10 scale?

Yes. Set the default `min`, `max` and `step` in the configuration, or define `ratingScale()` on a model to give it its own scale. Values may have up to 4 decimal places.

## How do I change a rating's comment only?

Get the rating with `ratingFor()` and call `update(['comment' => '...'])` on it, like any Eloquent model.

## Is {package.name} free to use?

Yes. {package.name} is open source under the {package.license} license, so you can use it in personal and commercial projects.

## Where can I find the {package.name} documentation?

Read the [{package.name} documentation]({package.docsUrl}) for setup, configuration, and usage examples.

## How do I report a bug or contribute to {package.name}?

Open an issue or a pull request on [GitHub]({package.githubUrl}), ask in [GitHub Discussions](https://github.com/orgs/Pharaonic/discussions), or chat with us on [Discord](https://discord.gg/XQG9RhvEvf).
