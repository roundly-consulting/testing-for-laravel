<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;

// Green smoke for the presets not already run by the dogfood ArchTest: each must
// register a PASSING case against a clean target, proving the preset wires up.

$archFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/'.$path;

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Testing\Arch');

ArchPresets::modelsResolveThroughSeam($archFixture('seam/green'));

ArchPresets::runtimeRequireIsWhitelisted($archFixture('composer/whitelisted.json'));
