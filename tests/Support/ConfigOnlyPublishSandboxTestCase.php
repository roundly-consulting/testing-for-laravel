<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\Concerns\UsesPublishSandbox;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\SandboxedPackage\SandboxedPackageServiceProvider;

/**
 * Sandboxes `config/` only — `database/` and `app/` stay the skeleton's.
 */
class ConfigOnlyPublishSandboxTestCase extends PackageTestCase
{
    use UsesPublishSandbox;

    protected function packageProviders(): array
    {
        return [SandboxedPackageServiceProvider::class];
    }

    protected function publishSandboxPaths(): array
    {
        return ['config'];
    }

    protected function preparePublishSandbox(string $root): void
    {
        file_put_contents($root.'/config/sandboxed.php', "<?php\n\nreturn ['host' => true];\n");
    }
}
