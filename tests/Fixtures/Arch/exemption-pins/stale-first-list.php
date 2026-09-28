<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;

// The mirror: preset ONE's list is stale, preset two's is live. Proves each pin is bound to
// its own preset's list rather than both pins sharing one (or the last write winning).

ArchPresets::finalByDefault(
    'RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen',
    ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\Bogus\NeverExisted'],
);

ArchPresets::noDebuggingLeftovers(
    ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen'],
    dirname(__DIR__).'/ShadowGreen',
);
