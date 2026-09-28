<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\KiosksManager;

/**
 * A model trait outside Concerns\ and Traits\ that delegates to the manager.
 */
trait OpensBooths
{
    public function open(): static
    {
        app(KiosksManager::class)->open($this);

        return $this;
    }
}
