<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Gateway;

// `Gateway` is a real class — it passes the existence check — but it lives in `Shadow`, not
// in the `ShadowGreen` namespace this preset scans. It exempts nothing there, and the pin
// must say so. Not collected by the suite — driven as a subprocess.

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Gateway::class]);
