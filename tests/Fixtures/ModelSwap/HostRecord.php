<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host app's own subclass, swapped in before boot. Uses {@see CountsCreations} so
 * the model-swap assertion can prove rows were really created *as this class* — not
 * merely returned as an instance of it.
 */
class HostRecord extends Record
{
    use CountsCreations;
}
