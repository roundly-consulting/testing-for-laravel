<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * The pin/shadow guards proven where they actually live: in a **registered Pest case**, in a
 * real file, driven end to end.
 *
 * Asserting `ArchExemptions::assert()` throws (ArchPresetLogicTest does, and should) proves
 * the assertion works — it says nothing about whether the preset still *registers* it, binds
 * it to the right list, or gives it a description Pest will accept. The defect being fixed
 * here lived entirely in that gap: every unit test stayed green while a second `$ignoring`
 * list took the whole file down at collection. So these fixtures are run as a subprocess and
 * the verdict is read from **JUnit XML** — a structured per-case pass/fail, not scraped
 * output. A dropped registration cannot hide: an expected case that is absent fails the
 * assertion just as loudly as one that passed when it should have failed.
 */
$fixture = fn (string $name): string => dirname(__DIR__).'/Fixtures/Arch/exemption-pins/'.$name.'.php';

/**
 * Run one fixture arch file in its own Pest process.
 *
 * @return array<string, bool> case description => passed
 */
$runArchFixture = function (string $file): array {
    $junit = tempnam(sys_get_temp_dir(), 'arch-pin-').'.xml';

    $process = new Process(
        [PHP_BINARY, 'vendor/bin/pest', $file, '--log-junit', $junit, '--colors=never'],
        dirname(__DIR__, 2),
    );

    $process->run();

    expect($junit)->toBeFile();

    $xml = simplexml_load_file($junit);
    @unlink($junit);

    expect($xml)->not->toBeFalse();

    $results = [];

    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        // A case is green only with no failure AND no error child — an errored case that
        // read as "not failed" would be exactly the silent pass this package exists to end.
        $results[(string) $case['name']] = count($case->failure) === 0 && count($case->error) === 0;
    }

    expect($results)->not->toBeEmpty(
        'The fixture registered no cases at all — that is a collection error, not a pass.',
    );

    return $results;
};

$finalPin = 'it preset: every arch exemption for classes are final by default in RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen still silences something';
$debugPin = 'it preset: every arch exemption for no debugging leftovers still silences something';

it('registers a distinct, passing pin for each of two exemption lists in one file', function () use ($runArchFixture, $fixture, $finalPin, $debugPin): void {
    $results = $runArchFixture($fixture('two-live-lists'));

    // Both pins present => the second registration was not dropped to dodge the collision,
    // and their descriptions differ => Pest accepted both in one file.
    expect($results)->toHaveKey($finalPin)
        ->and($results)->toHaveKey($debugPin)
        ->and($finalPin)->not->toBe($debugPin);

    expect($results[$finalPin])->toBeTrue()
        ->and($results[$debugPin])->toBeTrue();

    // Nothing else in the file may be red either.
    expect(array_keys(array_filter($results, fn (bool $passed): bool => ! $passed)))->toBe([]);
});

it('lets all four exemption-taking presets carry a list in one file', function () use ($runArchFixture, $fixture): void {
    // The ceiling, and the migration proof for the four blocked packages: crypto,
    // http-client-rate-limits and media-library each pair finalByDefault with
    // noLocalCryptoPrimitives; kubernetes-api pairs it with noDebuggingLeftovers — the shape
    // that had no fluent form to retreat to and so was hard-blocked outright.
    $results = $runArchFixture($fixture('every-preset-carries-a-list'));

    $pins = array_filter(
        array_keys($results),
        fn (string $name): bool => str_contains($name, 'every arch exemption for '),
    );

    // One pin per list, all distinct, none dropped.
    expect($pins)->toHaveCount(4);

    expect(array_keys(array_filter($results, fn (bool $passed): bool => ! $passed)))->toBe([]);
});

it('still fails a stale exemption on the SECOND list in a file', function () use ($runArchFixture, $fixture, $finalPin, $debugPin): void {
    $results = $runArchFixture($fixture('stale-second-list'));

    // The bite, on the preset that used to be impossible to reach: red.
    expect($results)->toHaveKey($debugPin)
        ->and($results[$debugPin])->toBeFalse();

    // ...and only it. The first preset's pin is bound to its own live list, so it stays green.
    expect($results)->toHaveKey($finalPin)
        ->and($results[$finalPin])->toBeTrue();
});

it('still fails a stale exemption on the FIRST list when the second is live', function () use ($runArchFixture, $fixture, $finalPin, $debugPin): void {
    // The mirror of the case above. Together they prove each pin checks its OWN preset's
    // list: one shared or last-write-wins binding could not produce both results.
    $results = $runArchFixture($fixture('stale-first-list'));

    expect($results)->toHaveKey($finalPin)
        ->and($results[$finalPin])->toBeFalse();

    expect($results)->toHaveKey($debugPin)
        ->and($results[$debugPin])->toBeTrue();
});

it('still reports classes shadowed by the SECOND finalByDefault exemption list', function () use ($runArchFixture, $fixture): void {
    $results = $runArchFixture($fixture('shadowed-second-preset'));

    $green = 'it preset: classes hidden by a prefix-matched exemption are still final in RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen';
    $shadowed = 'it preset: classes hidden by a prefix-matched exemption are still final in RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow';

    // Two shadow cases in one file at all — impossible while this description was fixed too.
    expect($results)->toHaveKey($green)
        ->and($results)->toHaveKey($shadowed);

    // The second one bites: `Provider` prefix-shadows the non-final ProviderClient/Proxy.
    expect($results[$shadowed])->toBeFalse()
        ->and($results[$green])->toBeTrue();

    // Pest's own arch case stays GREEN on that same namespace — it prefix-excluded the very
    // classes the shadow case just reported. That contrast IS the recovery's whole reason to
    // exist, so pin it: if this ever goes red, Pest changed and the docs need revisiting.
    expect($results['preset: classes are final by default in RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow'])->toBeTrue();
});

it('fails a stale exemption on the facade preset, in its pin and in the case itself', function () use ($runArchFixture, $fixture): void {
    $results = $runArchFixture($fixture('facade-seam-stale'));

    $label = 'models go through the facade in RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets';

    // The existence pin: `Models\Gadget` names nothing.
    expect($results)->toHaveKey("it preset: every arch exemption for {$label} still silences something")
        ->and($results["it preset: every arch exemption for {$label} still silences something"])->toBeFalse();

    // The case: the same entry exempts no violating class.
    expect($results)->toHaveKey("it preset: {$label}")
        ->and($results["it preset: {$label}"])->toBeFalse();
});

it('fails each Pest-arch preset pointed at a namespace that holds nothing', function () use ($runArchFixture, $fixture): void {
    $results = $runArchFixture($fixture('empty-namespace'));
    $namespace = 'RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGren';

    foreach (["strict types in {$namespace}", "classes are final by default in {$namespace}", "no local crypto primitives in {$namespace}"] as $label) {
        // The companion case bites...
        expect($results)->toHaveKey("it preset: {$label} has something to check")
            ->and($results["it preset: {$label} has something to check"])->toBeFalse();

        // ...where Pest's own arch case, over the same empty set, reports green. That contrast
        // is the reason the companion exists; if Pest ever fails an empty target itself, this
        // goes red and the companion can be revisited.
        expect($results)->toHaveKey("preset: {$label}")
            ->and($results["preset: {$label}"])->toBeTrue();
    }
});

it('fails an exemption that exists but lives outside the namespace the preset scans', function () use ($runArchFixture, $fixture, $finalPin): void {
    $results = $runArchFixture($fixture('out-of-scope-exemption'));

    expect($results)->toHaveKey($finalPin)
        ->and($results[$finalPin])->toBeFalse();
});
