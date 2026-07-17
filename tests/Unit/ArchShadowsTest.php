<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchShadows;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Gateway;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\GatewayClient;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Provider;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\ProviderBase;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\ProviderClient;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\ProviderProxy;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Unrelated;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Sol;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Solo;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Solo\Client as SoloClient;

const SHADOW_NS = 'RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow';

const SOLO_NS = 'RoundlyConsulting\Testing\Tests\Fixtures\Arch\Solo';

// ---------------------------------------------------------------------------
// The defect, upstream and pinned.
//
// Pest excludes an object when str_starts_with($object->name, $exclude) — a string
// PREFIX test, not class identity (pest-plugin-arch/src/Blueprint.php:103). The
// GREEN arch case in ArchPresetsGreenTest is the live proof that this still happens;
// these cases prove the recovery built on top of it.
// ---------------------------------------------------------------------------

it('reports a class an exemption silences without naming it', function (): void {
    // The bite that used to pass. `Provider` is exempted; `ProviderClient` is not, shares
    // its prefix, and is therefore invisible to the rule nobody thinks it escaped.
    expect(ArchShadows::shadowed(SHADOW_NS, [Provider::class]))
        ->toHaveKey(ProviderClient::class)
        ->and(ArchShadows::shadowed(SHADOW_NS, [Provider::class])[ProviderClient::class])
        ->toBe(Provider::class);
});

it('does not report the exactly-exempted class itself', function (): void {
    // `Provider` is meant to be exempt — that decision is visible and rot-checked. Only
    // what the exemption reaches BEYOND its own name is the defect.
    expect(ArchShadows::shadowed(SHADOW_NS, [Provider::class]))
        ->not->toHaveKey(Provider::class);
});

it('does not report a class that merely shares the namespace', function (): void {
    // The control. `Unrelated` sits in the same namespace but shares no prefix, so a
    // detector that flagged it would be matching the namespace rather than the exemption —
    // and would fire on every class in every package that exempts anything.
    expect(ArchShadows::shadowed(SHADOW_NS, [Provider::class]))
        ->not->toHaveKey(Unrelated::class);
});

it('does not report a shadowed class that is itself explicitly exempted', function (): void {
    // The documented remedy for a shadow you actually want: name it. It is then an argued,
    // visible, rot-checked entry rather than a silent side effect of its neighbour.
    expect(ArchShadows::shadowed(SHADOW_NS, [Provider::class, ProviderClient::class]))
        ->not->toHaveKey(ProviderClient::class);
});

it('treats a namespace exemption as intended rather than accidental', function (): void {
    // Shadowing is sometimes the POINT: Pest supports a namespace prefix in ->ignoring()
    // deliberately, and ArchExemptions already accepts one as live. Banning prefixes
    // outright would reject a correct exemption for being what it is. The distinction comes
    // from the exemption itself — naming a class means you meant that class; naming a
    // namespace means you meant the subtree.
    expect(ArchShadows::shadowed(SHADOW_NS, [SHADOW_NS]))->toBe([]);
});

it('resolves a namespace to its own PSR-4 root, exactly as Pest does', function (): void {
    // Scope, pinned — this was wrong on the first cut and caught by measurement.
    //
    // This package maps `RoundlyConsulting\Testing\` to src and `...\Testing\Tests\` to
    // tests. Pest's ObjectsRepository resolves DOWNWARD only, so expect('RoundlyConsulting
    // \Testing') scans src and never tests — which is why this package's 23 non-final
    // test-support classes do not fail finalByDefault. A scan that unioned every root under
    // the namespace would pull tests/ in and report classes the rule never covered: loss
    // reported where no coverage existed.
    expect(ArchShadows::shadowed('RoundlyConsulting\Testing', [Provider::class]))->toBe([]);

    // ...and the same exemption against the fixture's OWN namespace does report it. Without
    // this half, the emptiness above would pass just as well on a lookup that finds nothing.
    expect(ArchShadows::shadowed(SHADOW_NS, [Provider::class]))->toHaveKey(ProviderClient::class);
});

it('sees a namespace that is satisfied by a same-named file as well as a directory', function (): void {
    // `Solo.php` sits beside `Solo/`, so the class `...\Arch\Solo` IS the namespace
    // `...\Arch\Solo` — a manager-plus-sub-namespace shape Pest resolves through a dedicated
    // branch (`$fileOrDirectory.'.php'`), and one this scan must mirror or the class silently
    // drops out of the population it belongs to.
    //
    // `Sol` is the exemption rather than `Solo`, and that is what makes this test bite. Were
    // `Solo` exempted, `Solo\Client` would still be reported with the file branch deleted —
    // an exemption is resolved with class_exists(), not from the population — so the test
    // would pass over the very code it claims to cover. Verified by mutation: with the branch
    // stubbed out, that version stayed green and this one goes red.
    //
    // Exempting `Sol` instead makes `Solo` itself a VICTIM, and a victim can only be seen if
    // the population holds it.
    expect(ArchShadows::shadowed(SOLO_NS, [Sol::class]))->toBe([
        Solo::class => Sol::class,           // reachable only through the same-named file
        SoloClient::class => Sol::class,     // reachable through the directory walk
    ]);
});

// ---------------------------------------------------------------------------
// The recovery: re-apply finalByDefault to what Pest dropped.
// ---------------------------------------------------------------------------

it('fails, naming the class an exemption hid and the exemption that hid it', function (): void {
    expect(fn () => ArchShadows::assertShadowedClassesAreFinal(SHADOW_NS, [Provider::class]))
        ->toThrow(AssertionFailedError::class, 'ProviderClient');
});

it('names every class it found, not just the first', function (): void {
    // Two classes hide behind the one exemption. A message reporting one of them costs the
    // package a second round on a defect it was already told about.
    try {
        ArchShadows::assertShadowedClassesAreFinal(SHADOW_NS, [Provider::class]);
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('ProviderClient')
            ->toContain('ProviderProxy')
            // The exemption that hid them is the actionable half: without it the reader
            // cannot tell why a class they never exempted is being reported.
            ->toContain('hidden by the exemption')
            ->toContain(Provider::class);

        return;
    }

    $this->fail('A prefix-shadowed non-final class did not fail the shadow assertion.');
});

it('accepts a shadowed class that already satisfies the rule', function (): void {
    // The fleet's normal case, and the reason this restores coverage instead of demanding a
    // declaration: 27 of 28 shadowed classes measured across 12 packages are already final.
    // `GatewayClient` is shadowed by `Gateway` and final, so nothing was lost and nothing is
    // asked of the package.
    ArchShadows::assertShadowedClassesAreFinal(SHADOW_NS, [Gateway::class]);

    expect(ArchShadows::shadowed(SHADOW_NS, [Gateway::class]))->toHaveKey(GatewayClient::class);
});

it('passes when nothing is exempted at all', function (): void {
    // finalByDefault only registers the recovery when $ignoring is non-empty; this pins the
    // underlying call as safe on an empty list rather than relying on that guard.
    ArchShadows::assertShadowedClassesAreFinal(SHADOW_NS, []);

    expect(ArchShadows::shadowed(SHADOW_NS, []))->toBe([]);
});

it('ignores an exemption that names a class outside the namespace under test', function (): void {
    // A foreign exemption is legitimate (git exempts a Facade from another namespace) and
    // must not drag its own neighbours into this namespace's report.
    expect(ArchShadows::shadowed(SHADOW_NS, [ArchShadows::class]))->toBe([]);
});

it('does not treat a shadowed abstract class as a finality violation', function (): void {
    // Population parity with the preset: `abstract final` is a PHP fatal, so an abstract
    // class can never satisfy the ban and flagging one is a false positive by construction —
    // and an unfixable one. finalByDefault excludes abstracts, so the recovery must too.
    //
    // `ProviderBase` really is shadowed by `Provider` (it shares the prefix), so this is the
    // exclusion being exercised rather than a name that never matched: the two live
    // shadows are reported from the very same call, and it is not.
    expect(ArchShadows::shadowed(SHADOW_NS, [Provider::class]))
        ->not->toHaveKey(ProviderBase::class)
        ->toHaveKey(ProviderClient::class)
        ->toHaveKey(ProviderProxy::class);

    // The abstract must not be reported as OPEN either — it is non-final, so a population
    // filter applied in only one of the two places would surface it here.
    ArchShadows::assertShadowedClassesAreFinal(SHADOW_NS, [Provider::class, ProviderClient::class, ProviderProxy::class]);
});
