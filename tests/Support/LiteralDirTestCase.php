<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\Concerns\LoadsProviderMigrations;
use RoundlyConsulting\Testing\PackageTestCase;

/**
 * A suite that names its migrations by **literal directory** rather than by provider —
 * the branch of {@see LoadsProviderMigrations} that
 * has to verify the directory is really there.
 *
 * That verification runs in defineDatabaseMigrations(), i.e. once per test, so it must
 * contribute ZERO assertions to the test's own count: a setup precondition is not
 * something the test under it asserted.
 */
class LiteralDirTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [];
    }

    protected function migrationSources(): array
    {
        return [dirname(__DIR__).'/Fixtures/green/bare-constrained'];
    }
}
