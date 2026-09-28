<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Cart;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\AddItem;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support\HasLines;

/**
 * A per-area model (not under Models\) that calls an action directly — the bypass the
 * three-namespace scan missed.
 */
class Cart extends Model
{
    use HasLines;

    public function add(string $sku): void
    {
        app(AddItem::class)->execute($sku);
    }
}
