<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;

// One typo'd letter (`ShadowGren`) in the namespace. Pest's arch layer resolves it to no
// directory and passes all three cases over an empty set; the companion case each preset
// registers must go red instead. Not collected by the suite — driven as a subprocess.

ArchPresets::strictTypes('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGren');

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGren');

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGren');
