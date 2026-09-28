<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;

// All FOUR presets that take `$ignoring`, each carrying a list, in one file — the ceiling.
// Between them these cover every blocked repo's real shape: crypto, http-client-rate-limits
// and media-library pair finalByDefault with noLocalCryptoPrimitives; kubernetes-api pairs
// finalByDefault with noDebuggingLeftovers, the shape with no fluent fallback.

ArchPresets::strictTypes('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::noDebuggingLeftovers(
    ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen'],
    dirname(__DIR__).'/ShadowGreen',
);
