<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Tests\Fixtures\AutoLoadingPackage\AutoLoadingPackageServiceProvider;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;

uses(MinimalPackageTestCase::class);

/**
 * A provider that never booted registered nothing — so "it does not auto-load its
 * migrations" is trivially true of it. The auto-loading fixture provider is NOT in this
 * app, and the guard used to pass it anyway: a TestCase that forgot its provider got a
 * green publish-only pin for free.
 */
it('fails when the provider is not registered in the app', function (): void {
    expect(fn (): mixed => expect(AutoLoadingPackageServiceProvider::class)->toNotAutoLoadMigrations())
        ->toThrow(AssertionFailedError::class, 'is not registered');
});
