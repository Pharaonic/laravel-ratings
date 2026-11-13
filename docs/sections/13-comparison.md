## Compared with Likes, Follows, Favorites, and Bookmarks

Ratings belongs to the same family as [Laravel Likes](/packages/laravel/likes), [Follows](/packages/laravel/follows), [Favorites](/packages/laravel/favorites), and [Bookmarks](/packages/laravel/bookmarks). They share the same shape: a `Can…` trait on the actor, a `Has…` trait on the target, one table with a unique index per pair, string ids for any key type, the same cleanup on delete, and events dispatched only on real changes.

| | Likes, Follows, Favorites | Bookmarks | Ratings |
| --- | --- | --- | --- |
| Stored with the pair | nothing | JSON metadata | a value on a scale and a comment |
| Write | `like()` returns `bool` | `bookmark()` returns the Bookmark | `rate()` returns a `RatingResult` |
| Toggle | `toggleLike()` | `toggleBookmark()` | none: a rating has a value, so it is set or removed |
| Read one | `hasLiked()` | `bookmarkOf()`, `hasBookmarked()` | `ratingFor()`, `hasRated()` |
| Remove | `unlike()` | `unbookmark()` | `unrate()` |
| Updated event | no | `BookmarkUpdated` | `RatingUpdated`, with the previous value and comment |
| Aggregates | `withCount()` | `withCount()` | count, average, sum, distribution, `whereRatingAtLeast()` |

The intentional differences:

- **A result instead of a boolean.** A rating can be created, changed, or left as it was, and callers usually need to know which and what the value was before, so `rate()` returns a `RatingResult`.
- **No toggle.** Toggling a value makes no sense; `rate()` sets it and `unrate()` removes it.
- **Omitted is not null.** Like bookmark metadata, an omitted comment keeps what is stored. Unlike bookmarks, where `null` also keeps the metadata, `null` clears a comment, because "no comment" is a value a rating can have.
- **Scales.** Values are checked against the rated model's scale on every write, including direct saves of the model.
