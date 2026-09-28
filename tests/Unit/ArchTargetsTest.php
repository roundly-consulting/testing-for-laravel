<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchExemptions;
use RoundlyConsulting\Testing\Arch\ArchTargets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Gateway;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\DriverClient;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Engine;

$green = 'RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen';

it('resolves a namespace to every object under it through the PSR-4 map', function () use ($green): void {
    expect(array_keys(ArchTargets::in($green)))->toBe([Driver::class, DriverClient::class, Engine::class]);
});

it('resolves a single class target to its own file', function (): void {
    expect(ArchTargets::in(Gateway::class))->toHaveKey(Gateway::class);
});

it('resolves a typo to nothing', function (): void {
    expect(ArchTargets::in('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGren'))->toBe([]);
});

it('passes when something is left to check', function () use ($green): void {
    ArchTargets::assertSomethingToCheck($green, [Driver::class], 'finalByDefault', concreteClassesOnly: true);

    expect(true)->toBeTrue();
});

it('fails a namespace that resolves to nothing', function (): void {
    expect(fn () => ArchTargets::assertSomethingToCheck('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGren', [], 'strictTypes'))
        ->toThrow(AssertionFailedError::class, 'found nothing under');
});

it('fails when every object is exempted', function () use ($green): void {
    expect(fn () => ArchTargets::assertSomethingToCheck($green, [$green], 'strictTypes'))
        ->toThrow(AssertionFailedError::class, 'nothing left to check');
});

it('matches exemptions by prefix the way Pest does, except where shadows are re-checked', function () use ($green): void {
    // `Driver` hides `DriverClient` from Pest's arch case too, so for strictTypes only Engine is left…
    expect(fn () => ArchTargets::assertSomethingToCheck($green, [Driver::class, Engine::class], 'strictTypes'))
        ->toThrow(AssertionFailedError::class, 'nothing left to check');

    // …while finalByDefault's shadow recovery still checks DriverClient, so it is not vacuous.
    ArchTargets::assertSomethingToCheck($green, [Driver::class, Engine::class], 'finalByDefault', concreteClassesOnly: true);
});

it('counts only concrete classes for finalByDefault', function (): void {
    // Facades\Teams\Actions\Contracts holds one interface: files exist, concrete classes do not.
    $contracts = 'RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Contracts';

    ArchTargets::assertSomethingToCheck($contracts, [], 'strictTypes');

    expect(fn () => ArchTargets::assertSomethingToCheck($contracts, [], 'finalByDefault', concreteClassesOnly: true))
        ->toThrow(AssertionFailedError::class, 'every concrete class');
});

it('fails an existing exemption outside the scanned namespace, and passes one inside it', function () use ($green): void {
    ArchExemptions::assert([Driver::class, $green], $green);

    expect(fn () => ArchExemptions::assert([Gateway::class], $green))
        ->toThrow(AssertionFailedError::class, 'match nothing under');
});
