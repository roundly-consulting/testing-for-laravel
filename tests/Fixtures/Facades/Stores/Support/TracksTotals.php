<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\RecalculateCart;

/**
 * Lives in Support\, not Concerns\ or Traits\ — a subject only because a model uses it.
 */
trait TracksTotals
{
    public function total(): int
    {
        return app(RecalculateCart::class)->execute();
    }
}
