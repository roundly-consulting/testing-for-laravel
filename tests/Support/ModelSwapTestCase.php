<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\HostRecord;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\ModelSwapServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap\RecordObserver;

/**
 * Boots the throwaway model-swap package with `HostRecord` swapped in *before boot*, so
 * the model-swap self-tests exercise a correctly-seamed configuration and can drive the
 * broken flows against it.
 */
class ModelSwapTestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        HostRecord::resetCreationCount();
        RecordObserver::reset();
    }

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
        $this->swapModel('model_swap.record_model', HostRecord::class);

        parent::defineEnvironment($app);
    }
}
