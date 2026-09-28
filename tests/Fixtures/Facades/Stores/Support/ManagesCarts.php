<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\AddItem;

/**
 * A trait that splits a manager, used by no model: calling actions is its job.
 */
trait ManagesCarts
{
    public function addToCart(string $sku): void
    {
        app(AddItem::class)->execute($sku);
    }
}
