<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\DebugLeftovers;

$fixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/debug/'.$path;

/**
 * The defect this preset was built to have: `ray` is not in the dependency graph (policy),
 * Pest's arch layer only sees symbols that exist, so `expect(['ray'])->not->toBeUsed()`
 * silently passed on a file that plainly calls it — 0-for-every-run. A token scan does not
 * ask whether the function exists.
 */
it('catches a ray leftover that the arch layer could never see', function () use ($fixture): void {
    expect(fn () => DebugLeftovers::assert($fixture('leftovers')))
        ->toThrow(AssertionFailedError::class, 'RayLeftover.php: ray()');
});

it('catches a dd leftover', function () use ($fixture): void {
    expect(fn () => DebugLeftovers::assert($fixture('leftovers')))
        ->toThrow(AssertionFailedError::class, 'DdLeftover.php: dd()');
});

/**
 * The near-misses must stay green, or the ban is unusable: a docblock naming dd() and ray(),
 * a `$collection->dump()`, and a class's own static `::dump()` are all legitimate — and the
 * docblock case is why this scans tokens rather than raw text.
 */
it('passes on code whose only dd/ray mentions are prose, methods and statics', function () use ($fixture): void {
    DebugLeftovers::assert($fixture('green'));
});

it('exempts a class by name', function () use ($fixture): void {
    expect(fn () => DebugLeftovers::assert($fixture('leftovers'), [
        'Fixture\Debug\Leftovers\RayLeftover',
    ]))->toThrow(AssertionFailedError::class, 'DdLeftover.php: dd()');
});

it('exempts a whole namespace', function () use ($fixture): void {
    DebugLeftovers::assert($fixture('leftovers'), ['Fixture\Debug\Leftovers']);
});

it('fails when the source directory does not exist', function (): void {
    expect(fn () => DebugLeftovers::assert('/nope/not/here'))
        ->toThrow(AssertionFailedError::class);
});
