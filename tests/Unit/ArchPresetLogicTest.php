<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Arch\ModelSeam;
use RoundlyConsulting\Testing\Arch\RuntimeRequires;

$archFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/'.$path;

// ---------------------------------------------------------------------------
// noLocalCryptoPrimitives — the preset is a Pest arch case over the ban list; its
// live green run against a clean namespace is in ArchPresetsGreenTest. Pest's arch
// layer only reliably scans src-mapped namespaces, so the bite is proven
// deterministically here: the ban list contains exactly the primitive the fixture
// re-implements, so pointing the preset at that code goes red.
// ---------------------------------------------------------------------------

it('bans exactly the openssl primitive a local verifier re-implements', function () use ($archFixture): void {
    $source = (string) file_get_contents($archFixture('crypto/LocalVerifier.php'));

    $called = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            $called[] = $token[1];
        }
    }

    expect(array_values(array_intersect($called, ArchPresets::CRYPTO_PRIMITIVES)))
        ->not->toBeEmpty()
        ->toContain('openssl_verify');
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
