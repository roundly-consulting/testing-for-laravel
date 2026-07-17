<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host subclass that forgot {@see CountsCreations}.
 *
 * This is the shape that used to downgrade the assertion in silence — 15 assertions became 13
 * and nothing said so, dropping the only check that can tell a row really created as the host
 * class from one created as the packaged class and re-hydrated.
 */
class UncountedHostRecord extends Record {}
