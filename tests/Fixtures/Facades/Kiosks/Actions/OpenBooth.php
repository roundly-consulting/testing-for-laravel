<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Booths\Booth;

final class OpenBooth
{
    public function execute(Booth $booth): Booth
    {
        return $booth;
    }
}
