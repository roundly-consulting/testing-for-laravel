<?php

declare(strict_types=1);

/**
 * Pest sits in this package's `require` (the dev-only carve-out), so its constraint decides
 * which suites can install the package at all. A Pest 5 suite needs `^5.0` in here; a Pest 4
 * suite must keep working. The OR is deliberate: consumers pin one major, this package must be
 * co-installable with both. CI pins the major per leg with a temporary `--with` constraint,
 * so the committed constraint is what this reads.
 */
it('accepts both pest majors', function (string $section, string $package): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer[$section][$package] ?? null)->toBe('^4.0|^5.0');
})->with([
    'pest' => ['require', 'pestphp/pest'],
    'laravel plugin' => ['require-dev', 'pestphp/pest-plugin-laravel'],
]);
