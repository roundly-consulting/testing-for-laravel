<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\FakePackage\FakePackageServiceProvider;

/**
 * Concrete {@see PackageTestCase} over the throwaway fake package — exercises provider
 * registration, provider-class migration loading, the before-boot config hook, and a
 * before-boot model swap in one Testbench run.
 */
class FakePackageTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [FakePackageServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [FakePackageServiceProvider::class];
    }

    protected function configBeforeBoot(): array
    {
        return ['fake.enabled' => true];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $this->swapModel('fake.widget_model', FakeWidget::class);

        parent::defineEnvironment($app);
    }
}
