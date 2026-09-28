<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\SwappableModels;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SwappableModel;

/**
 * An arch file bound to no TestCase — the README's own tests/Pest.php binds only Feature and
 * Unit — has no application, and the config half of the check used to die with
 * "Target class [config] does not exist". It must say what is missing instead.
 */
it('explains that the config half needs the booted application', function (): void {
    $previous = Container::getInstance();
    Container::setInstance(new Container);

    try {
        expect(fn () => SwappableModels::assertEntry(SwappableModel::class, 'arch.record_model'))
            ->toThrow(AssertionFailedError::class, 'needs the booted application');
    } finally {
        Container::setInstance($previous);
    }
});
