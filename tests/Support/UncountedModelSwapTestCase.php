<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\ModelSwapServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\UncountedHostRecord;

/**
 * The same throwaway model-swap package, but with a host subclass that forgot
 * `CountsCreations` swapped in before boot — a correctly-seamed configuration in every other
 * respect, so the only thing the assertion can object to is the missing trait.
 *
 * Swapping it in properly is what makes this a real test of the trait requirement rather than
 * of the boot-order guard, which fires first and would otherwise mask it.
 */
class UncountedModelSwapTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [ModelSwapServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [ModelSwapServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->swapModel('model_swap.record_model', UncountedHostRecord::class);

        parent::defineEnvironment($app);
    }
}
