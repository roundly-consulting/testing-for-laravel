<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\KeptSchemaServiceProvider;

/**
 * A {@see PackageTestCase} that keeps its migrated schema between tests, on whichever engine
 * the leg runs.
 */
class KeptSchemaTestCase extends PackageTestCase
{
    /**
     * Fed to {@see self::schemaSignatureExtras()}, so a test can make its next migrate differ.
     */
    public static ?string $flavour = null;

    protected function packageProviders(): array
    {
        return [KeptSchemaServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [KeptSchemaServiceProvider::class];
    }

    protected function keepsMigratedSchema(): bool
    {
        return true;
    }

    protected function schemaSignatureExtras(): array
    {
        return ['flavour' => self::$flavour];
    }
}
