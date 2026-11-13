<?php

use Illuminate\Database\QueryException;
use Pharaonic\Laravel\Ratings\Support\RatingManager;

test('only unique violations are recognized', function (string $sqlState, ?int $driverCode, string $message, bool $unique) {
    $pdo = new PDOException($message);
    $pdo->errorInfo = [$sqlState, $driverCode, $message];

    $exception = new QueryException('insert into "ratings" ...', [], $pdo);

    $this->assertSame($unique, RatingManager::isUniqueViolation($exception));
})->with([
    'mysql duplicate entry' => ['23000', 1062, "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'ratings_unique_pair'", true],
    'mysql not null' => ['23000', 1048, "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'rater_id' cannot be null", false],
    'mysql foreign key' => ['23000', 1452, 'SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row', false],
    'mysql gone away' => ['HY000', 2006, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', false],
    'pgsql unique violation' => ['23505', 7, 'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "ratings_unique_pair"', true],
    'pgsql not null' => ['23502', 7, 'SQLSTATE[23502]: Not null violation: 7 ERROR: null value in column "rater_id"', false],
    'sqlite unique' => ['23000', 19, 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: ratings.rateable_type, ratings.rateable_id', true],
    'sqlite not null' => ['23000', 19, 'SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: ratings.rater_id', false],
    'sqlite locked' => ['HY000', 5, 'SQLSTATE[HY000]: General error: 5 database is locked', false],
    'sqlsrv duplicate key' => ['23000', 2627, 'SQLSTATE[23000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Violation of UNIQUE KEY constraint', true],
    'sqlsrv unique index' => ['23000', 2601, 'SQLSTATE[23000]: [Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Cannot insert duplicate key row', true],
]);
