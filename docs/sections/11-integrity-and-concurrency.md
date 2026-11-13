## Integrity and Concurrency

Two requests can rate the same model for the same user at the same moment: a double-clicked button, a retried request, two tabs. The package keeps the table and the results correct when they do.

### One rating per pair

The table has a unique index on the rater's and the rated model's type and id. It is what keeps each pair to one rating: when two requests insert the same pair together, the database accepts one insert and refuses the other, and the refused call updates the row the other one created. One call reports `created`; the other reports `updated` or `unchanged`.

The index fits the key limits of MySQL and MariaDB with `utf8mb4` (2,040 bytes, under InnoDB's 3,072), and also serves every query on one model's ratings, aggregates included.

### The last committed write wins

Each write locks the rating's row before it reads what the rating holds. Concurrent writes to one rating therefore run one after the other, and each call's `previous()` is the value the write before it committed:

- no update is lost: every call's change was stored, then replaced only by a later one;
- the stored rating is the one from the call that committed last, which is not always the request that arrived last;
- a call without a comment keeps whatever comment the row has when it writes, even if another request just set it.

### Removal during a write

When another request deletes the rating while `rate()` is writing it, `rate()` creates it again, so its result describes a rating that exists. If the row keeps vanishing (3 times in a row), it throws a `RuntimeException` you can retry, rather than reporting a rating that was not stored.

### Transactions and retries

Each write runs in a transaction on the rating model's connection:

- outside a transaction of yours, a deadlock or a locked SQLite database is retried up to 3 times;
- inside one, the error goes to you, because the database already rolled your transaction back: retry the whole transaction yourself;
- the insert runs in a savepoint, so a refused duplicate leaves your PostgreSQL transaction usable;
- any other database error, including a violation of another unique index of yours, is thrown at once, never retried or hidden.

Every read inside a write uses the write connection, so a lagging read replica cannot hide a rating that was just written.

### What is tested

The tests run separate PHP processes that write to one pair at the same moment, against SQLite, MySQL 5.7 and 8.4, MariaDB 10.6, and PostgreSQL 16: simultaneous first ratings, updates, value-only updates, removals, and ratings mixed with removals.
