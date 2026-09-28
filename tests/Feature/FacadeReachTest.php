<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Facades\Ledger;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Internal\RecordAudit;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Maintenance\PruneTeams;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Facades\Teams;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Support\Pruner;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamHandle;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions\ArchiveWidget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions\NotifySubscribers;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions\PublishWidget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Facades\Widgets;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\WidgetsManager;
use RoundlyConsulting\Testing\Tests\Support\FacadesTestCase;

uses(FacadesTestCase::class);

$actions = fn (string $set): string => fixturePath("Facades/{$set}/Actions");

// ---------------------------------------------------------------------------
// Green.
// ---------------------------------------------------------------------------

it('reaches an action two sub-accessor hops away, and one held by a $via helper', function () use ($actions): void {
    // AddMember is referenced only in MembersAccessor (manager -> for() -> members());
    // PruneTeams only in Pruner, which the manager holds but never returns. RecordAudit is
    // @internal and the abstract/interface/trait declarations are not actions at all.
    expect(Teams::class)->toReachEveryAction($actions('Teams'), via: [Pruner::class]);
});

it('scans the class the container binds to a contract accessor', function () use ($actions): void {
    expect(Ledger::class)->toReachEveryAction($actions('Ledger'));
});

it('composes with the other facade expectations in any order', function () use ($actions): void {
    // toBeFakeable() restores the real binding, so the contract's manager is still scanned.
    expect(Ledger::class)
        ->toBeFakeable()
        ->toReachEveryAction($actions('Ledger'))
        ->toDocumentItsRoot();
});

it('looks through an active fake to the manager it extends', function () use ($actions): void {
    Teams::fake();

    expect(Teams::class)->toReachEveryAction($actions('Teams'), via: [Pruner::class]);
});

it('accepts a live $except entry for an action that is really unreachable', function () use ($actions): void {
    expect(Widgets::class)->toReachEveryAction($actions('Widgets'), except: [ArchiveWidget::class, NotifySubscribers::class]);
});

it('is reachable through the static Assert twin', function () use ($actions): void {
    Assert::facadeReachesEveryAction(Teams::class, $actions('Teams'), via: [Pruner::class]);

    expect(fn () => Assert::facadeReachesEveryAction(Widgets::class, $actions('Widgets')))
        ->toThrow(AssertionFailedError::class, ArchiveWidget::class);
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('rejects an action reachable only through a model (and a DTO)', function () use ($actions): void {
    try {
        expect(Widgets::class)->toReachEveryAction($actions('Widgets'));
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain(ArchiveWidget::class.' is not reachable from '.Widgets::class)
            ->toContain('expose it through the facade (flat method or sub-accessor) or mark it `@internal` if it is a building block')
            // Composed by PublishWidget only: an action is never surface, so being reachable
            // through another action does not count — it must be exposed or marked @internal.
            ->toContain(NotifySubscribers::class.' is not reachable')
            // Reached by the manager, so not reported.
            ->not->toContain(PublishWidget::class.' is not reachable');

        return;
    }

    $this->fail('An action reachable only through a model was accepted.');
});

it('rejects an action held by a helper the walk cannot see, without $via', function () use ($actions): void {
    try {
        expect(Teams::class)->toReachEveryAction($actions('Teams'));
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain(PruneTeams::class.' is not reachable')
            ->not->toContain(RecordAudit::class)
            ->toContain('Surface scanned: ');

        return;
    }

    $this->fail('An action reachable only through an unreturned helper was accepted.');
});

it('rejects a contract accessor when no application is booted, and says why', function () use ($actions): void {
    $app = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);

    try {
        expect(fn () => expect(Ledger::class)->toReachEveryAction($actions('Ledger')))
            ->toThrow(AssertionFailedError::class, 'is an interface and no application is booted');
    } finally {
        Facade::setFacadeApplication($app);
    }
});

it('rejects a contract accessor while a fake with nothing behind it is active', function () use ($actions): void {
    Ledger::fake();

    expect(fn () => expect(Ledger::class)->toReachEveryAction($actions('Ledger')))
        ->toThrow(AssertionFailedError::class, 'currently resolves to the fake');
});

it('rejects an empty actions directory rather than passing over nothing', function (): void {
    expect(fn () => expect(Teams::class)->toReachEveryAction(fixturePath('Facades/EmptyActions')))
        ->toThrow(AssertionFailedError::class, 'No class is declared under');
});

it('rejects a directory holding only non-host-facing classes', function () use ($actions): void {
    expect(fn () => expect(Teams::class)->toReachEveryAction($actions('Teams').'/Internal'))
        ->toThrow(AssertionFailedError::class, 'no host-facing action to reach');
});

it('rejects a missing actions directory', function (): void {
    expect(fn () => expect(Teams::class)->toReachEveryAction(fixturePath('Facades/Nowhere/Actions')))
        ->toThrow(AssertionFailedError::class, 'Actions directory does not exist');
});

it('rejects a stale $except entry', function () use ($actions): void {
    // Reachable already.
    expect(fn () => expect(Widgets::class)->toReachEveryAction($actions('Widgets'), except: [ArchiveWidget::class, NotifySubscribers::class, PublishWidget::class]))
        ->toThrow(AssertionFailedError::class, 'reachable from the facade already')
        // Not a host-facing action (it is @internal) — and not an action at all.
        ->and(fn () => expect(Teams::class)->toReachEveryAction($actions('Teams'), except: [RecordAudit::class], via: [Pruner::class]))
        ->toThrow(AssertionFailedError::class, 'not a host-facing action under')
        ->and(fn () => expect(Teams::class)->toReachEveryAction($actions('Teams'), except: ['RoundlyConsulting\Nope'], via: [Pruner::class]))
        ->toThrow(AssertionFailedError::class, 'not a host-facing action under');
});

it('rejects a $via entry that does not exist', function () use ($actions): void {
    expect(fn () => expect(Teams::class)->toReachEveryAction($actions('Teams'), via: ['RoundlyConsulting\Nope\Helper']))
        ->toThrow(AssertionFailedError::class, 'does not exist');
});

it('rejects a $via entry that reaches nothing new', function () use ($actions): void {
    // TeamHandle is already on the surface (TeamsManager::for() returns it).
    expect(fn () => expect(Teams::class)->toReachEveryAction($actions('Teams'), via: [Pruner::class, TeamHandle::class]))
        ->toThrow(AssertionFailedError::class, '$via '.TeamHandle::class.': makes no action reachable');
});

it('reports a binding that throws instead of resolving the root', function () use ($actions): void {
    app()->bind(WidgetsManager::class, fn () => throw new RuntimeException('boom'));

    expect(fn () => expect(Widgets::class)->toReachEveryAction($actions('Widgets')))
        ->toThrow(AssertionFailedError::class, 'Resolving app('.WidgetsManager::class.') threw: boom');
});

it('falls back to the accessor type when the binding resolves no object', function () use ($actions): void {
    app()->bind(WidgetsManager::class, fn () => 'not an object');

    expect(Widgets::class)->toReachEveryAction($actions('Widgets'), except: [ArchiveWidget::class, NotifySubscribers::class]);
});
