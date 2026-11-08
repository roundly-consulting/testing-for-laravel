<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\Concerns\UsesPublishSandbox;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\SandboxedPackage\SandboxedPackageServiceProvider;

/**
 * A package suite's publish case: the provider resolves its publish destinations while it
 * registers, so the sandbox has to be in place before then.
 */
class PublishSandboxTestCase extends PackageTestCase
{
    use UsesPublishSandbox;

    protected function packageProviders(): array
    {
        return [SandboxedPackageServiceProvider::class];
    }
}
