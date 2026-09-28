<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\DocblockOnlyFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\InstanceFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\NoSwapFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\NotASubtypeFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\RootOnlyFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\StringKeyed;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\UnionFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\UntypedFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Contracts\Ledger as LedgerContract;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Facades\Ledger;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\LedgerManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Facades\Teams;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Testing\TeamsFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Facades\Widgets;
use RoundlyConsulting\Testing\Tests\Support\FacadesTestCase;

uses(FacadesTestCase::class);

// ---------------------------------------------------------------------------
// Green: a real fake(), a subtype of the root, installed for the facade AND for DI.
// ---------------------------------------------------------------------------

it('accepts a fake that extends the manager', function (): void {
    expect(Teams::class)->toBeFakeable();
});

it('accepts a fake that implements the contract', function (): void {
    expect(Ledger::class)->toBeFakeable();
});

it('is reachable through the static Assert twin', function (): void {
    Assert::facadeIsFakeable(Teams::class);

    expect(fn () => Assert::facadeIsFakeable(NoSwapFake::class))->toThrow(AssertionFailedError::class);
});

it('leaves the facade and the container as it found them', function (): void {
    // Resolved before: the same singleton instance must come back afterwards.
    $manager = app(LedgerContract::class);

    expect(Ledger::class)->toBeFakeable();

    expect(app(LedgerContract::class))->toBe($manager)
        ->and(Ledger::getFacadeRoot())->toBe($manager);

    // Never resolved before: afterwards it resolves to the real class again, not the fake.
    expect(Teams::class)->toBeFakeable();

    expect(app(TeamsManager::class))->not->toBeInstanceOf(TeamsFake::class)
        ->and(Teams::getFacadeRoot())->toBeInstanceOf(TeamsManager::class)
        ->and(Teams::getFacadeRoot())->not->toBeInstanceOf(TeamsFake::class);
});

it('restores the real root even when the fake is rejected half-installed', function (): void {
    // RootOnlyFake installs the fake behind the facade, then fails the container check.
    expect(fn () => expect(RootOnlyFake::class)->toBeFakeable())->toThrow(AssertionFailedError::class);

    expect(Teams::getFacadeRoot())->toBeInstanceOf(TeamsManager::class)
        ->not->toBeInstanceOf(TeamsFake::class)
        ->and(app(LedgerContract::class))->toBeInstanceOf(LedgerManager::class);
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('rejects a facade without fake()', function (): void {
    expect(fn () => expect(Widgets::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'has no fake()');
});

it('rejects a fake() that exists only in the docblock', function (): void {
    expect(fn () => expect(DocblockOnlyFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'exists only as a `@method` docblock line');
});

it('rejects a fake() that is not static', function (): void {
    expect(fn () => expect(InstanceFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'must be `public static`');
});

it('rejects a fake() without a declared class return type', function (): void {
    expect(fn () => expect(UntypedFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'must declare its fake class as the return type (got: none)');
});

it('rejects a fake() whose return type is a union', function (): void {
    expect(fn () => expect(UnionFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, '(got: a union or intersection type)');
});

it('rejects a fake that is not a subtype of the accessor type', function (): void {
    expect(fn () => expect(NotASubtypeFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'is not a subtype of the accessor type '.TeamsManager::class);
});

it('rejects a fake() that never installs the fake', function (): void {
    expect(fn () => expect(NoSwapFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'did not install it');
});

it('rejects a fake() that installs the fake behind the facade but not in the container', function (): void {
    expect(fn () => expect(RootOnlyFake::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'constructor-injected code bypasses the fake');
});

it('rejects a string accessor before looking for a fake', function (): void {
    expect(fn () => expect(StringKeyed::class)->toBeFakeable())
        ->toThrow(AssertionFailedError::class, 'not a class or interface');
});

it('needs the booted application to run fake()', function (): void {
    $app = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);

    try {
        expect(fn () => expect(Teams::class)->toBeFakeable())
            ->toThrow(AssertionFailedError::class, 'needs the booted application');
    } finally {
        Facade::setFacadeApplication($app);
    }
});
