<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ModelsThroughFacade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Traits\HasWidgets;

$teams = 'RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams';
$widgets = 'RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets';

// ---------------------------------------------------------------------------
// Green: Teams' model and model trait delegate to the manager.
// ---------------------------------------------------------------------------

it('accepts models and model traits that go through the manager', function () use ($teams): void {
    ModelsThroughFacade::assert($teams);
});

it('accepts a leading or trailing backslash on the namespace', function () use ($teams): void {
    ModelsThroughFacade::assert('\\'.$teams.'\\');
});

it('accepts violations silenced by live exemptions, by class or by namespace', function () use ($widgets): void {
    ModelsThroughFacade::assert($widgets, [Widget::class, HasWidgets::class]);
    ModelsThroughFacade::assert($widgets, [$widgets.'\Models', $widgets.'\Traits']);
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('rejects a model and a trait that call actions directly, naming each', function () use ($widgets): void {
    try {
        ModelsThroughFacade::assert($widgets);
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain(Widget::class.' uses '.$widgets.'\Actions\ArchiveWidget')
            // Reached through an aliased namespace import: `WidgetActions\PublishWidget`.
            ->toContain(HasWidgets::class.' uses '.$widgets.'\Actions, '.$widgets.'\Actions\PublishWidget')
            ->toContain("facade's fake() never sees the call");

        return;
    }

    test()->fail('A model calling an action directly was accepted.');
});

it('rejects a partial exemption list', function () use ($widgets): void {
    expect(fn () => ModelsThroughFacade::assert($widgets, [Widget::class]))
        ->toThrow(AssertionFailedError::class, HasWidgets::class.' uses');
});

it('rejects an exemption that silences no violation', function () use ($widgets, $teams): void {
    // A real class, so the existence pin passes — but it exempts nothing in this rule.
    expect(fn () => ModelsThroughFacade::assert($widgets, [Widget::class, HasWidgets::class, TeamsManager::class]))
        ->toThrow(AssertionFailedError::class, '$ignoring '.TeamsManager::class.': exempts no class')
        // Stale on a clean namespace too: once the violation is fixed, the exemption must go.
        ->and(fn () => ModelsThroughFacade::assert($teams, [$teams.'\Models\Team']))
        ->toThrow(AssertionFailedError::class, 'silences nothing');
});

it('rejects a namespace with no models, concerns or traits instead of passing vacuously', function (): void {
    expect(fn () => ModelsThroughFacade::assert('RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger'))
        ->toThrow(AssertionFailedError::class, 'should not call it')
        ->and(fn () => ModelsThroughFacade::assert('RoundlyConsulting\Nope'))
        ->toThrow(AssertionFailedError::class, 'should not call it');
});
