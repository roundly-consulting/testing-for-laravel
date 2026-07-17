<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Gateway;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Provider;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;

// The ArchShadows recovery must still RED for the SECOND finalByDefault in a file.
//
// Two finalByDefault calls, each with a list — a shape that was itself impossible before the
// fix, since the shadow case ALSO carried a fixed description. The first namespace is clean;
// the second exempts `Provider`, which prefix-shadows the non-final `ProviderClient` and
// `ProviderProxy`. Pest's own arch case stays green (it excludes them by prefix) — the
// shadow case is the only thing that reports them.

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow', [Provider::class, Gateway::class]);
