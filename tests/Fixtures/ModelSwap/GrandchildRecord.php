<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

/**
 * A subclass of {@see HostRecord}, used to prove `instanceof` is not enough: an instance
 * of this class IS an `instanceof HostRecord`, yet its concrete class is not HostRecord —
 * a naive `instanceof` check would pass where the model-swap assertion (correctly) fails.
 */
class GrandchildRecord extends HostRecord
{
    //
}
