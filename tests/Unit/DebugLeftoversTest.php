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

    expect(true)->toBeTrue();
});

it('catches the chained debug helpers Laravel puts on queries and collections', function () use ($fixture): void {
    $message = '';

    try {
        DebugLeftovers::assert($fixture('leftovers'));
    } catch (AssertionFailedError $failure) {
        $message = $failure->getMessage();
    }

    expect($message)->toContain('ChainedDdLeftover.php: ->dd()')
        ->toContain('ChainedDdLeftover.php: ->ddRawSql()')
        ->toContain('ChainedDdLeftover.php: ->dumpRawSql()');
});

it('leaves a chained ->dump() alone — too many legitimate methods share the name', function () use ($fixture): void {
    // Clean::run() calls `$items->dump()`; a `$yaml->dump()` or `$exporter->dump()` is ordinary API.
    DebugLeftovers::assert($fixture('green'));

    expect(true)->toBeTrue();
});

it('fails an exemption that matches no class in the scanned directory', function () use ($fixture): void {
    // A real, autoloadable namespace — but nothing in `green/` declares a class under it, so the
    // entry exempts nothing here.
    expect(fn () => DebugLeftovers::assert($fixture('green'), ['Fixture\Debug\Leftovers', 'Fixture\Debug\Green\Clean']))
        ->toThrow(AssertionFailedError::class, 'Fixture\Debug\Leftovers');
});

it('fails when the source directory does not exist', function (): void {
    expect(fn () => DebugLeftovers::assert('/nope/not/here'))
        ->toThrow(AssertionFailedError::class);
});

it('catches DD()/Var_Dump() regardless of case', function () use ($fixture): void {
    try {
        DebugLeftovers::assert($fixture('mixed-case'));
    } catch (AssertionFailedError $failure) {
        expect($failure->getMessage())
            ->toContain('MixedCaseLeftover.php: DD()')
            ->toContain('MixedCaseLeftover.php: Var_Dump()')
            ->toContain('MixedCaseLeftover.php: Print_R()')
            ->toContain('MixedCaseLeftover.php: ->DDRawSql()');

        return;
    }

    $this->fail('Mixed-case debug calls passed the ban.');
});

it('does not mistake an instantiated or static class for a debug call', function () use ($fixture): void {
    DebugLeftovers::assert($fixture('green-case'));
});
