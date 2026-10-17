<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\SeedingSchema\SeedingSchemaServiceProvider;

/**
 * Opts in to a kept schema whose migrations seed rows — which must fall back to the
 * drop-per-test reset.
 */
class SeedingKeptSchemaTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [SeedingSchemaServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [SeedingSchemaServiceProvider::class];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }
}
