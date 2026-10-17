<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;

// Green smoke for the seam presets over the bypass fixtures: each must register a PASSING
// case against a clean target. Kept apart from ArchPresetsGreenTest, which already registers
// a `modelsResolveThroughSeam` case under the same description.

$archFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/'.$path;

ArchPresets::modelsResolveThroughSeam($archFixture('SeamBypass/Green'), 'Support', [
    'arch.gadget_model',
    'arch.sprocket_model',
]);

ArchPresets::factoriesResolveThroughSeam($archFixture('FactorySeam/Green'), $archFixture('FactorySeam/Src'));
