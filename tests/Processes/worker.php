<?php

/*
 * One worker of ConcurrencyTest: boots the package, waits for the shared
 * start time, then acts on one pair and prints the result as JSON.
 *
 * Usage: php worker.php <rate|rate-value|unrate> <user id> <product id> <start time> <worker number>
 *
 * rate writes the value (worker % 5) + 1 and the comment "worker N"; rate-value
 * writes the value only, so the stored comment must be kept.
 */

use Illuminate\Support\Facades\Event;
use Pharaonic\Laravel\Ratings\Events\RatingCreated;
use Pharaonic\Laravel\Ratings\Events\RatingRemoved;
use Pharaonic\Laravel\Ratings\Events\RatingUpdated;
use Pharaonic\Laravel\Ratings\Tests\Concurrency\WorkerApplication;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\Product;
use Pharaonic\Laravel\Ratings\Tests\Fixtures\User;

require __DIR__.'/../../vendor/autoload.php';

[, $action, $userId, $productId, $start, $worker] = $argv;

(new WorkerApplication('worker'))->createApplication();

$events = ['created' => 0, 'updated' => 0, 'removed' => 0, 'previousComments' => []];
Event::listen(RatingCreated::class, function () use (&$events) {
    $events['created']++;
});
Event::listen(RatingUpdated::class, function (RatingUpdated $event) use (&$events) {
    $events['updated']++;
    $events['previousComments'][] = $event->previousComment;
});
Event::listen(RatingRemoved::class, function () use (&$events) {
    $events['removed']++;
});

$user = User::query()->findOrFail($userId);
$product = Product::query()->findOrFail($productId);
$value = ((int) $worker % 5) + 1;

if ((float) $start > microtime(true)) {
    time_sleep_until((float) $start);
}

switch ($action) {
    case 'rate':
    case 'rate-value':
        $rating = $action === 'rate'
            ? $user->rate($product, $value, 'worker '.$worker)
            : $user->rate($product, $value);

        $result = [
            'status' => $rating->status(),
            'previous' => $rating->previous(),
            'current' => $rating->current(),
            'id' => $rating->rating()->getKey(),
            'comment' => $rating->rating()->comment,
        ];

        break;
    default:
        $result = ['removed' => $user->unrate($product)];
}

echo json_encode($result + $events);
