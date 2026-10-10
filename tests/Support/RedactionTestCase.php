<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultServiceProvider;

/**
 * Boots an app with the Vault fixture contracts bound, so the interface-drift half of
 * `toRedactSensitiveArguments()` compares each contract with a real binding.
 */
class RedactionTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [VaultServiceProvider::class];
    }
}
