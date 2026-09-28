<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Concerns;

use RoundlyConsulting\Testing\Tests\Fixtures\NotInstalled\Base;

/**
 * In a scanned namespace but unloadable (its parent is not installed): scanned from source,
 * clean, and its traits cannot be walked — the scan carries on instead of crashing.
 */
final class UnloadableConcern extends Base {}
