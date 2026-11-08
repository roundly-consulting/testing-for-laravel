<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Foundation\Application;
use RoundlyConsulting\Testing\Concerns\UsesPublishSandbox;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\SandboxedPackage\SandboxedPackageServiceProvider;

/**
 * Overrides getPackageProviders() in the class that uses the trait — the documented way to keep
 * the sandbox is to alias the trait's method and call it.
 */
class AliasedPublishSandboxTestCase extends PackageTestCase
{
    use UsesPublishSandbox {
        getPackageProviders as sandboxProviders;
    }

    public static int $calls = 0;

    protected function packageProviders(): array
    {
        return [SandboxedPackageServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        self::$calls++;

        return $this->sandboxProviders($app);
    }
}
