<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Bare;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Duplicated;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Hollow;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\NoAccessor;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\NonFinal;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\NonStatic;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\ObjectAccessor;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Phantom;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\StringKeyed;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Suggested;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Undocumented;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\Unreadable;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\WrongCount;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Facades\Ledger;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Facades\Teams;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Testing\TeamsFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Facades\Widgets;

// ---------------------------------------------------------------------------
// Green: the docblock matches the root. Teams exercises everything that must NOT count
// against it — vendor Manager/Macroable methods, an implemented getDefaultDriver(), an
// @internal method, a fake() on the facade, assert helpers on the fake, and a sync() whose
// generics/shape/callable/default/variadic params must not miscount.
// ---------------------------------------------------------------------------

it('accepts a facade whose docblock matches its root exactly', function (): void {
    expect(Teams::class)->toDocumentItsRoot();
});

it('accepts a facade over a contract', function (): void {
    expect(Ledger::class)->toDocumentItsRoot()
        ->and(Widgets::class)->toDocumentItsRoot();
});

it('accepts a live $except entry for a real undocumented root method', function (): void {
    expect(Undocumented::class)->toDocumentItsRoot(['for', 'prune']);
});

it('is reachable through the static Assert twin', function (): void {
    Assert::facadeDocumentsItsRoot(Teams::class);

    expect(fn () => Assert::facadeDocumentsItsRoot(Undocumented::class))
        ->toThrow(AssertionFailedError::class, 'undocumented');
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('rejects a string container key as the accessor', function (): void {
    expect(fn () => expect(StringKeyed::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, "returns 'teams', which is not a class or interface");
});

it('rejects an accessor that is an object', function (): void {
    expect(fn () => expect(ObjectAccessor::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'returns an object instead of a class-string');
});

it('rejects a facade that never names an accessor', function (): void {
    expect(fn () => expect(NoAccessor::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'threw instead of naming its root');
});

it('rejects a subject that is not a facade, or does not exist', function (): void {
    expect(fn () => expect(TeamsManager::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'is not a facade')
        ->and(fn () => expect('RoundlyConsulting\Nope\Facades\Nope')->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'does not exist');
});

it('rejects a facade that is not final', function (): void {
    expect(fn () => expect(NonFinal::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'is not final');
});

it('names every undocumented root method, with the line to paste', function (): void {
    try {
        expect(Undocumented::class)->toDocumentItsRoot();
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('for(): public on '.TeamsManager::class.' but undocumented')
            ->toContain('@method static \RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamHandle for(\RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team $team)')
            ->toContain('prune(): public on')
            ->toContain('@method static int prune()')
            // Vendor API and @internal are never demanded.
            ->not->toContain('driver()')
            ->not->toContain('macro()')
            ->not->toContain('getDefaultDriver()')
            ->not->toContain('bootstrap()');

        return;
    }

    $this->fail('An undocumented root method was accepted.');
});

it('rejects a phantom method that exists nowhere — not on the root, the facade or its fake', function (): void {
    try {
        expect(Phantom::class)->toDocumentItsRoot();
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('archive(): documented, but no such method exists on the root '.TeamsManager::class)
            ->toContain('or its fake '.TeamsFake::class)
            ->toContain('assertArchived(): documented, but no such method exists')
            // fake() is a real static on the facade: not a phantom, not undocumented.
            ->not->toContain('fake():');

        return;
    }

    $this->fail('A phantom @method line was accepted.');
});

it('hands back a paste-ready line for every signature shape', function (): void {
    $broken = 'RoundlyConsulting\\Testing\\Tests\\Fixtures\\Facades\\Broken';

    try {
        expect(Suggested::class)->toDocumentItsRoot();
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain("@method static \\{$broken}\\UnrelatedFake|\\{$broken}\\HollowManager|null union(string|int \$key)")
            ->toContain("@method static ?\\{$broken}\\SuggestedManager intersection(\\Countable&\\Traversable \$items)")
            ->toContain("@method static void defaults(?string \$a = null, bool \$b = true, bool \$c = false, int \$d = 3, float \$e = 1.5, string \$f = 'x', array \$map)")
            ->toContain('@method static mixed untyped($value)');

        return;
    }

    $this->fail('A facade without @method lines was accepted.');
});

it('rejects a documented parameter count that differs from the real method', function (): void {
    try {
        expect(WrongCount::class)->toDocumentItsRoot();
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('create(): documents 1 parameter(s), but')
            ->toContain('takes 2')
            ->toContain('sync(): documents 2 parameter(s), but')
            ->toContain('takes 4. Expected: @method static int sync(array $map, \Closure $resolver, array $defaults = [], string ...$tags)');

        return;
    }

    $this->fail('A wrong parameter count was accepted.');
});

it('rejects a method documented twice', function (): void {
    expect(fn () => expect(Duplicated::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'create(): documented more than once');
});

it('rejects a docblock with no @method line, suggesting every one', function (): void {
    try {
        expect(Bare::class)->toDocumentItsRoot();
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('has no `@method static` line')
            ->toContain('@method static \RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team create(string $name, array $options = [])')
            ->toContain('@method static \RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager fresh()');

        return;
    }

    $this->fail('An empty docblock was accepted.');
});

it('rejects a @method line written without static', function (): void {
    expect(fn () => expect(NonStatic::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'prune(): written `@method` without `static`');
});

it('rejects a tag it cannot read', function (): void {
    expect(fn () => expect(Unreadable::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'unreadable tag `@method static int`');
});

it('rejects a stale $except entry that names no documentable method', function (): void {
    expect(fn () => expect(Teams::class)->toDocumentItsRoot(['archive']))
        ->toThrow(AssertionFailedError::class, "\$except 'archive': ")
        // An @internal or vendor method is not documentable, so exempting it is stale too.
        ->and(fn () => expect(Teams::class)->toDocumentItsRoot(['bootstrap']))
        ->toThrow(AssertionFailedError::class, "\$except 'bootstrap': ");
});

it('rejects an $except entry for a method that is documented anyway', function (): void {
    expect(fn () => expect(Teams::class)->toDocumentItsRoot(['prune']))
        ->toThrow(AssertionFailedError::class, 'the method is documented anyway');
});

it('rejects a root with nothing to document', function (): void {
    expect(fn () => expect(Hollow::class)->toDocumentItsRoot())
        ->toThrow(AssertionFailedError::class, 'has no public method for');
});
