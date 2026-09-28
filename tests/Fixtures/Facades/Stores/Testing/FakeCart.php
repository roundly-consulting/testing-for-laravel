<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Testing;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\AddItem;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Cart\Cart;

/**
 * A model under Testing\ — the one place allowed to stand in for actions.
 */
class FakeCart extends Cart
{
    public function add(string $sku): void
    {
        app(AddItem::class)->execute('fake-'.$sku);
    }
}
