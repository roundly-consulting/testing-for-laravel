<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchExemptions;
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

it('accepts a non-model class calling its own static query() helper', function () use ($archFixture): void {
    // The regression pin. The ban matched the bare token `self::query(`, which is only a
    // seam bypass when query() is Eloquent's. This fixture is not a model: `self::query()`
    // resolves to its own private helper, which itself goes through the seam. It was
    // flagged in two packages (approvals' ApprovalChecker, jwt), and the preset ships as
    // an it() case with no ->ignoring() escape — so the verdict was unappealable on
    // correct code.
    ModelSeam::assert($archFixture('seam/non-model-query'));
});

it('still rejects a seam bypass on a model that extends an intermediate base', function () use ($archFixture): void {
    // Guard the guard for the fix above: gating the ban on "is a model" must not become a
    // way to escape it. This model reaches Model only through a package base class, so a
    // token-level `extends Model` gate would silently stop banning it.
    expect(fn () => ModelSeam::assert($archFixture('seam/subclassed-model')))
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

// ---------------------------------------------------------------------------
// exemptionsExist — an arch exemption that silences nothing must fail, the same
// standard the config contract holds allowUnread/allowUnshipped to.
// ---------------------------------------------------------------------------

it('accepts an exemption naming a class that really exists', function (): void {
    ArchExemptions::assert([ArchPresets::class, ModelSeam::class]);
});

it('accepts an exemption naming a namespace that really holds code', function (): void {
    // ->ignoring() takes namespaces as well as class names, so "not a class" is not the
    // same as "stale" — checking only for classes would reject a legitimate exemption.
    ArchExemptions::assert(['RoundlyConsulting\Testing\Arch', 'RoundlyConsulting\Testing\Assertions']);
});

it('rejects an exemption that silences nothing', function (): void {
    // The shipped bug: `Types\Metric` where the real class is `Facades\Metric`. `::class`
    // on a non-existent class resolves to a string at compile time, so PHP never
    // complained and neither did Pest.
    expect(fn () => ArchExemptions::assert(['RoundlyConsulting\Testing\Types\Metric']))
        ->toThrow(AssertionFailedError::class, 'silence nothing');
});

it('rejects an empty exemption entry', function (): void {
    expect(fn () => ArchExemptions::assert(['']))
        ->toThrow(AssertionFailedError::class);
});

it('names every stale exemption, not just the first', function (): void {
    expect(fn () => ArchExemptions::assert(['App\Nope\One', ArchPresets::class, 'App\Nope\Two']))
        ->toThrow(AssertionFailedError::class, 'App\Nope\One, App\Nope\Two');
});
