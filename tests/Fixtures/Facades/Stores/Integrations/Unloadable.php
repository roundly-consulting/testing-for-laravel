<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Integrations;

use RoundlyConsulting\Testing\Tests\Fixtures\NotInstalled\Base;

/**
 * An integration whose parent is not installed: declared, but it cannot autoload — so it
 * cannot be a working model and the scan skips it rather than crashing.
 */
final class Unloadable extends Base {}
