<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support;

/**
 * Clean itself; reaches the action through the trait it uses — a subject two hops out.
 */
trait HasLines
{
    use RecalculatesLines;
}
