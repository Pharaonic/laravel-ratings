---
name: Ratings

action:
  label: View on Packagist
  href: "{package.packagistUrl}"

views: components.packages

breadcrumbs:
  - label: Home
    href: route:home
  - label: Packages
    href: route:packages.index
  - label: "{technology.name} Packages"
    href: "url:/packages/{technology.slug}"
  - label: "{package.name}"

card:
  topic: database
  icon: star
  tags: ratings rating reviews review stars star rating scores scale comments average distribution eloquent polymorphic morph users products uuid ulid soft deletes events unique index
  description: "Let any Eloquent model rate any other, once per rater, with a value on an exact scale and an optional comment: rate() creates or updates and reports which, and averages, counts and distributions come straight from SQL."

seo:
  title: "{package.fullName} - Polymorphic Star Ratings and Reviews for Eloquent"
  description: "{package.name} adds ratings between any two Laravel Eloquent models: one rating per rater, configurable and per-model scales with exact decimals, optional comments, created/updated/unchanged results, SQL aggregates, and database-enforced uniqueness. {package.downloadsShort}+ downloads, {package.license} licensed."
  keywords: laravel ratings, laravel rating package, eloquent ratings, laravel star rating, laravel reviews, laravel polymorphic ratings, laravel average rating, laravel rating distribution, laravel rate model, laravel product reviews
  author: Pharaonic
  images:
    - "{package.cover}"
  openGraph:
    type: website
    siteName: Pharaonic
  twitter:
    card: summary_large_image

schema:
  "@type": SoftwareSourceCode
  name: "{package.name}"
  description: "{package.name} is a Laravel package for polymorphic Eloquent ratings with scales, comments, and SQL aggregates."
  image: "{package.cover}"
  codeRepository: "{package.githubUrl}"
  programmingLanguage: PHP
  runtimePlatform: "{technology.name}"
  version: "{package.version}"
  datePublished: "{package.publishedAt}"
  dateModified: "{package.updatedAt}"
  license: "https://opensource.org/licenses/{package.license}"
  isAccessibleForFree: true
  sameAs:
    - "{package.githubUrl}"
    - "{package.packagistUrl}"
  author:
    "@id": url:/#organization
  publisher:
    "@id": url:/#organization
  interactionStatistic:
    "@type": InteractionCounter
    interactionType: https://schema.org/DownloadAction
    userInteractionCount: "{package.downloads}"
---
