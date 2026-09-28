<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;

// Two presets, two LIVE exemption lists. Every case must pass, and both pins must be
// registered under distinct descriptions. Not collected by the suite (no `Test.php`
// suffix) — driven as a subprocess so its result can be asserted rather than suffered.

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::noDebuggingLeftovers(
    ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen'],
    dirname(__DIR__).'/ShadowGreen',
);
