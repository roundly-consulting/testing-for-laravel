<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Gateway;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow\Provider;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen\Driver;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Traits\HasWidgets;

// Green smoke for the presets not already run by the dogfood ArchTest: each must
// register a PASSING case against a clean target, proving the preset wires up.

$archFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/'.$path;

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Testing\Arch');

ArchPresets::modelsResolveThroughSeam($archFixture('seam/green'));

ArchPresets::morphColumnsUseTheSeam($archFixture('morph-seam/seam'));

ArchPresets::runtimeRequireIsWhitelisted($archFixture('composer/whitelisted.json'));

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams');

/**
 * A live exemption list on the facade preset: Widgets' model and trait DO call actions, and
 * are exempted — so the preset case and the existence pin it registers must both pass.
 */
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets', [
    Widget::class,
    HasWidgets::class,
]);

/**
 * The shadow recovery, wired end to end: `Driver` is exempted and prefix-shadows
 * `DriverClient`, which is final. Both the preset and the recovery it auto-registers must
 * pass — a package that is simply correct pays nothing and declares nothing.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen', [Driver::class]);

/**
 * ## Pest's prefix matching, pinned as the upstream defect it is
 *
 * This case is **green on a codebase that violates the rule**, and that is the point.
 * `Shadow\ProviderClient` and `Shadow\ProviderProxy` are not final and not exempted, yet
 * exempting their neighbour `Provider` silences them: Pest excludes by
 * `str_starts_with($object->name, $exclude)` (pest-plugin-arch Blueprint.php:103).
 *
 * Written with Pest's raw arch expectation rather than `ArchPresets::finalByDefault()`
 * precisely because the preset now catches this — that is what
 * `ArchShadows::assertShadowedClassesAreFinal()` is for, and the same fixture goes red
 * through the preset in ArchShadowsTest.
 *
 * Not an endorsement: it is the tripwire. Should Pest ever match by class identity, this
 * goes RED, and the recovery plus every docblock explaining it must be revisited.
 */
arch('pins Pest matching arch exemptions by prefix rather than class identity')
    ->expect('RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow')
    ->classes()
    ->toBeFinal()
    ->ignoring([Provider::class, Gateway::class]);
