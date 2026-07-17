<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;

// This package dogfoods its own presets: the only way to trust `ArchPresets` is to run
// it against this very package. Everything here goes through `ArchPresets` — no
// hand-written strict/final/no-debug arch — except the app-surface pin below, which no
// preset expresses.

ArchPresets::strictTypes('RoundlyConsulting\Testing');

// Exemptions go through the parameter, not Pest's fluent ->ignoring(): the parameter form
// also registers the pin that fails when one of these stops silencing anything. Note the
// absence of PackageTestCase — it is abstract, and the preset now excludes abstract
// classes by construction rather than making every package exempt its own bases.
ArchPresets::finalByDefault('RoundlyConsulting\Testing', [
    // Lock-recording fixtures extend framework Builder/Grammar and are themselves
    // extension points for a consumer's suite.
    'RoundlyConsulting\Testing\Fixtures\LockRecordingBuilder',
    'RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar',
]);

ArchPresets::noDebuggingLeftovers();

// The app-facing promise: the static assertion surface (Assert / Expectations / arch
// presets / assertion implementations) must be usable in any Laravel app with no
// Testbench installed. Only the package base case and its concerns may touch Testbench.
arch('no testbench outside the package base case and its concerns')
    ->expect('RoundlyConsulting\Testing')
    ->not
    ->toUse('Orchestra\Testbench')
    ->ignoring([
        'RoundlyConsulting\Testing\PackageTestCase',
        'RoundlyConsulting\Testing\Concerns',
    ]);
