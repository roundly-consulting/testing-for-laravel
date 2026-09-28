<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\AddItem;

/**
 * Not a model and not a model trait: calling actions is its job.
 */
final class Checkout
{
    public function run(): void
    {
        app(AddItem::class)->execute('checkout');
    }
}
