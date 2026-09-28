<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Traits\HasWidgets;

// The facade preset with a STALE exemption: the third entry names no class. Its existence pin
// must go red, and so must the preset case (the entry exempts no violation). Not collected by
// the suite (no `Test.php` suffix) — driven as a subprocess.

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets', [
    Widget::class,
    HasWidgets::class,
    'RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Gadget',
]);
