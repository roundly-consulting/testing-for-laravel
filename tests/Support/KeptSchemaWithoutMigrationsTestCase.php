<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\KeptSchemaServiceProvider;

/**
 * Opts in to a kept schema but names no migration sources — a host that gets the package as
 * another package's dependency and never migrates it, or a suite that builds its fixture tables
 * by hand. Nothing is migrated, so there is nothing to keep: the drop-per-test reset stays in
 * charge, on whichever engine the leg runs.
 */
class KeptSchemaWithoutMigrationsTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [KeptSchemaServiceProvider::class];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }
}
