<?php

use Illuminate\Contracts\Console\Kernel;
use Pharaonic\Laravel\Ratings\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * The package migration, as Laravel records it.
 */
const RATINGS_MIGRATION = '2026_11_13_000001_create_pharaonic_ratings_table';

/**
 * Run an Artisan command and expect it to succeed.
 *
 * @param  array<string, mixed>  $parameters
 */
function runArtisan(string $command, array $parameters = []): void
{
    expect(app(Kernel::class)->call($command, $parameters))->toBe(0);
}
