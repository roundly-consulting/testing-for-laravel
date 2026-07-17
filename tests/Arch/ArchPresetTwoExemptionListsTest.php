<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;

/**
 * The acceptance case for two exemption lists in ONE file, run live rather than described.
 *
 * A fixed pin description capped a file at a single `$ignoring` list: the second preset to
 * carry one was a hard `Pest\Exceptions\TestAlreadyExist` at collection, which took the whole
 * file down. Four packages legitimately carry two lists (crypto, http-client-rate-limits,
 * kubernetes-api, media-library), and `$ignoring` is the only route to the rot-check and to
 * the `ArchShadows` recovery — so a package that could not use it got neither guarantee.
 *
 * This file is the fix's floor, and it works precisely because it is an ordinary collected
 * test file: if the pin descriptions ever collide again, this does not fail, it **errors at
 * collection**, and the fleet's whole arch surface is one edit from the same wall.
 *
 * The second preset is deliberately `noDebuggingLeftovers` — the hard-blocked shape. It
 * registers an `it()` case and has no fluent `->ignoring()` to fall back to, so before this
 * fix it could not share a file with any other exemption list at all.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::noDebuggingLeftovers(
    ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen'],
    dirname(__DIR__).'/Fixtures/Arch/debug/green',
);
