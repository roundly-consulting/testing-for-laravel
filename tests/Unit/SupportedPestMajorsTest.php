<?php

declare(strict_types=1);

/**
 * Pest sits in this package's `require` (the dev-only carve-out), so its constraint decides
 * which suites can install the package at all. Since 2.0 that is Pest 5 only: Laravel 13 is the
 * only supported Laravel, and PHP 8.6 runs nothing older (Pest 4's parallel runner caps PHP at
 * 8.5). A widened constraint here would let a Pest 4 suite install a package that never tests it.
 */
it('requires pest 5', function (string $section, string $package): void {
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($composer[$section][$package] ?? null)->toBe('^5.0');
})->with([
    'pest' => ['require', 'pestphp/pest'],
    'laravel plugin' => ['require-dev', 'pestphp/pest-plugin-laravel'],
]);
