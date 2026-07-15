<?php

declare(strict_types=1);

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

it('registers through the pest plugin bootable', function (): void {
    Expectations::flush();

    (new Plugin)->boot();

    expect(Expectations::registered())->toBeTrue();
});

it('exposes the toHaveRunnableMigrationOrder expectation once registered', function (): void {
    Expectations::register();

    expect(fixturePath('green/bare-constrained'))->toHaveRunnableMigrationOrder(1);
});
