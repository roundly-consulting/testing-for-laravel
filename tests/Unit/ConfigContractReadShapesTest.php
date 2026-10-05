<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;

function contractFailure(string $fixture, ?array $srcDirs = null): string
{
    $base = configContractFixture($fixture);

    try {
        ConfigContract::assert($base.'/config/shop.php', $srcDirs ?? $base.'/src', 'shop');
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    return '';
}

// ---------------------------------------------------------------------------
// Fully-qualified and aliased spellings of the same read.
// ---------------------------------------------------------------------------

it('counts \config(), the fully-qualified facade, an aliased facade and the global alias', function (): void {
    expect(contractFailure('fully-qualified'))->toBe('');
});

it('catches a typo in a fully-qualified \config() read', function (): void {
    expect(contractFailure('fully-qualified-typo'))
        ->toContain('FORWARD')
        ->toContain('shop.payments.key');
});

// ---------------------------------------------------------------------------
// The repository reached through an expression.
// ---------------------------------------------------------------------------

it('counts reads through config(), app("config"), the container and $app["config"]', function (): void {
    expect(contractFailure('helper-receivers'))->toBe('');
});

it('does not count a lookup on some other service that shares the prefix', function (): void {
    expect(contractFailure('helper-receivers-cache'))
        ->toContain('REVERSE')
        ->toContain('shop.token');
});

// ---------------------------------------------------------------------------
// package-toolkit-for-laravel's readers.
// ---------------------------------------------------------------------------

it('proves every shipped key read only through the toolkit readers, with no options', function (): void {
    expect(contractFailure('toolkit-readers'))->toBe('');
});

// ---------------------------------------------------------------------------
// A write is not a read.
// ---------------------------------------------------------------------------

it('treats config([...]) as a write rather than an unresolvable key', function (): void {
    expect(contractFailure('runtime-write'))->toBe('');
});

// ---------------------------------------------------------------------------
// Forward: nothing lives below a scalar.
// ---------------------------------------------------------------------------

it('fails a read below a scalar leaf, which is always null', function (): void {
    expect(contractFailure('scalar-leaf'))
        ->toContain('FORWARD')
        ->toContain('shop.cache.store')
        ->toContain('below the scalar');
});

it('still accepts reads below an empty map, a list or a null placeholder', function (): void {
    expect(contractFailure('container-leaves'))->toBe('');
});

// ---------------------------------------------------------------------------
// Blade views are readers too.
// ---------------------------------------------------------------------------

it('reads config from a Blade view passed as a source directory', function (): void {
    $base = configContractFixture('blade');

    // Every Blade shape that runs PHP — echoes, raw echoes, directive arguments, component
    // bindings, @php and <?php blocks — counts; the comment, the escaped echo and the
    // @verbatim block do not (those keys are not shipped, so counting them would go red).
    expect(contractFailure('blade', [$base.'/src', $base.'/resources/views']))->toBe('');
});

it('still reports the view-read keys when the views directory is not scanned', function (): void {
    expect(contractFailure('blade'))
        ->toContain('REVERSE')
        ->toContain('shop.banner');
});

// ---------------------------------------------------------------------------
// The key passed as a named argument.
// ---------------------------------------------------------------------------

it("counts config(key: 'pkg.x')", function (): void {
    expect(contractFailure('named-key'))->toBe('');
});
