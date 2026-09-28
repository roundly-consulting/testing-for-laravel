<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\RecalculateCart;

trait RecalculatesLines
{
    public function recalculate(): int
    {
        return app(RecalculateCart::class)->execute();
    }
}
