<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Arch\ModelSeam;
use RoundlyConsulting\Testing\Arch\RuntimeRequires;

$archFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/'.$path;

// ---------------------------------------------------------------------------
// noLocalCryptoPrimitives — proves the exact ban list catches a local primitive.
// (The preset registers a Pest arch case; here we drive its ban list directly and
// force the lazy arch expectation to verify so the failure is observable.)
// ---------------------------------------------------------------------------

it('rejects a local openssl call re-implementing a crypto primitive', function (): void {
    expect(fn () => expect('RoundlyConsulting\Testing\Tests\Fixtures\Arch\Crypto')
        ->not
        ->toUse(ArchPresets::CRYPTO_PRIMITIVES)
        ->ensureLazyExpectationIsVerified())
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// modelsResolveThroughSeam — green, then proves-it-bites.
// ---------------------------------------------------------------------------

it('accepts a package that resolves models only through the seam', function () use ($archFixture): void {
    ModelSeam::assert($archFixture('seam/green'));
});

it('rejects a static::query() helper that bypasses the configured seam', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/static-query')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a self::query() helper that bypasses the configured seam', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/self-query')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a new static instantiation that bypasses the configured seam', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/new-static')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a swap config literal read outside the seam directory', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/stray-literal')))
        ->toThrow(AssertionFailedError::class);
});

it('fails on a missing source directory rather than passing vacuously', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/does-not-exist')))
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// runtimeRequireIsWhitelisted — green, then proves-it-bites.
// ---------------------------------------------------------------------------

it('accepts a require block of only whitelisted vendors', function () use ($archFixture): void {
    RuntimeRequires::assert($archFixture('composer/whitelisted.json'));
});

it('rejects a disallowed third-party vendor in require', function () use ($archFixture): void {
    expect(fn () => RuntimeRequires::assert($archFixture('composer/disallowed.json')))
        ->toThrow(AssertionFailedError::class);
});

it('accepts a would-be-disallowed vendor once it is explicitly allowed', function () use ($archFixture): void {
    RuntimeRequires::assert($archFixture('composer/disallowed.json'), [
        'acme/laravel-medialibrary',
        'guzzlehttp/guzzle',
    ]);
});
