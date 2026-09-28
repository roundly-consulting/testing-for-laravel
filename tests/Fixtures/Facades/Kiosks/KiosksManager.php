<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Actions\OpenBooth;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Booths\Booth;

/**
 * The path: the manager is the one class model code calls, and it resolves the action.
 */
class KiosksManager
{
    public function open(Booth $booth): Booth
    {
        return app(OpenBooth::class)->execute($booth);
    }
}
