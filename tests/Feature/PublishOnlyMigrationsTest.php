<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\AutoLoadingPackage\AutoLoadingPackageServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\PublishOnlyPackage\PublishOnlyPackageServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\UntimestampedPublishPackage\UntimestampedPublishServiceProvider;
use RoundlyConsulting\Testing\Tests\Support\PublishGuardTestCase;

uses(PublishGuardTestCase::class);

// ---------------------------------------------------------------------------
// toNotAutoLoadMigrations
// ---------------------------------------------------------------------------

it('passes when a provider does not auto-load its migrations', function (): void {
    expect(PublishOnlyPackageServiceProvider::class)->toNotAutoLoadMigrations();
});

it('accepts an explicit migrations directory override', function (): void {
    expect(PublishOnlyPackageServiceProvider::class)
        ->toNotAutoLoadMigrations(__DIR__.'/../Fixtures/PublishOnlyPackage/database/migrations');
});

it('bites when a provider auto-loads its migrations', function (): void {
    expect(fn (): mixed => expect(AutoLoadingPackageServiceProvider::class)->toNotAutoLoadMigrations())
        ->toThrow(AssertionFailedError::class);
});

it('passes the autoload guard through the static escape hatch', function (): void {
    Assert::doesNotAutoLoadMigrations(PublishOnlyPackageServiceProvider::class);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// toPublishMigrationsTimestamped
// ---------------------------------------------------------------------------

it('pins the timestamped publish map', function (): void {
    expect(PublishOnlyPackageServiceProvider::class)
        ->toPublishMigrationsTimestamped('publish-only-migrations', 2);
});

it('bites on a wrong publish count', function (): void {
    expect(fn (): mixed => expect(PublishOnlyPackageServiceProvider::class)
        ->toPublishMigrationsTimestamped('publish-only-migrations', 5))
        ->toThrow(AssertionFailedError::class);
});

it('bites on an unknown publish tag', function (): void {
    expect(fn (): mixed => expect(PublishOnlyPackageServiceProvider::class)
        ->toPublishMigrationsTimestamped('no-such-tag', 2))
        ->toThrow(AssertionFailedError::class);
});

it('bites on an untimestamped destination', function (): void {
    expect(fn (): mixed => expect(UntimestampedPublishServiceProvider::class)
        ->toPublishMigrationsTimestamped('untimestamped-migrations', 1))
        ->toThrow(AssertionFailedError::class);
});

it('passes the publish guard through the static escape hatch', function (): void {
    Assert::publishesMigrationsTimestamped(PublishOnlyPackageServiceProvider::class, 'publish-only-migrations', 2);

    expect(true)->toBeTrue();
});
