<?php

declare(strict_types=1);

use Pest\Expectation;
use RoundlyConsulting\Testing\Expectations\Expectations;
use RoundlyConsulting\Testing\Pest\Plugin;

afterEach(fn () => Expectations::register());

it('registers idempotently', function (): void {
    Expectations::flush();
    expect(Expectations::registered())->toBeFalse();

    Expectations::register();
    expect(Expectations::registered())->toBeTrue();

    // Second call is a no-op, not an error.
    Expectations::register();
    expect(Expectations::registered())->toBeTrue();
});

// CI runs this suite on Pest 4 and Pest 5; the bootable case below then proves the plugin
// hook on whichever major the leg installed.
it('runs on a supported pest major', function (): void {
    expect((int) explode('.', Pest\version())[0])->toBeIn([4, 5]);
});

it('registers through the pest plugin bootable', function (): void {
    Expectations::flush();

    (new Plugin)->boot();

    expect(Expectations::registered())->toBeTrue();
});

it('exposes the toHaveRunnableMigrationOrder expectation once registered', function (): void {
    Expectations::register();

    expect(fixturePath('green/bare-constrained'))->toHaveRunnableMigrationOrder(1);
});

// Pest keeps extensions in one static list, so an expectation registered once stays registered
// for the whole process. Taking it out first is the only way to prove each path adds it.
it('adds toRedactSensitiveArguments through both registration paths', function (Closure $path): void {
    $extends = new ReflectionProperty(Expectation::class, 'extends');
    $saved = $extends->getValue();

    try {
        $without = $saved;
        unset($without['toRedactSensitiveArguments']);
        $extends->setValue(null, $without);
        Expectations::flush();

        expect(Expectation::hasExtend('toRedactSensitiveArguments'))->toBeFalse();

        $path();

        expect(Expectation::hasExtend('toRedactSensitiveArguments'))->toBeTrue();
    } finally {
        $extends->setValue(null, $saved);
    }
})->with([
    // The parameter is typed Closure, so Pest hands these over uncalled.
    'register()' => fn () => Expectations::register(),
    'the pest plugin' => fn () => (new Plugin)->boot(),
]);
