<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Actions\OpenBooth;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Booths\Booth;

/**
 * Not a model: resolving an action is allowed here.
 */
final class Scheduler
{
    public function openAll(Booth $booth): void
    {
        app(OpenBooth::class)->execute($booth);
    }
}
