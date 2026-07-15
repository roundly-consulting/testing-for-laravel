<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SwappableModel;

/**
 * Boots a minimal app with the swap config key defaulting to the correctly-seamed
 * {@see SwappableModel}, so the `swappableModelsAreNotFinal` preset and the
 * `toBeSwappableVia` expectation have a config default to check against.
 */
class ArchPresetsTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [];
    }

    protected function configBeforeBoot(): array
    {
        return [
            'arch.record_model' => SwappableModel::class,
        ];
    }
}
