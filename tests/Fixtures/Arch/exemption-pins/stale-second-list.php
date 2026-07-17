<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;

// The rot-check must still BITE for the SECOND list in a file. Preset one's list is live;
// preset two's names a class that does not exist. Only preset two's pin may go red.

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::noDebuggingLeftovers(
    ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\Bogus\NeverExisted'],
    dirname(__DIR__).'/debug/green',
);
