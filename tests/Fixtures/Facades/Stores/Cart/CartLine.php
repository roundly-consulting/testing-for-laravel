<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Cart;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support\HasLines;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Support\TracksTotals;

/**
 * Clean itself — but the Support\ trait it uses calls an action. HasLines is shared with
 * Cart: reported once.
 */
class CartLine extends Model
{
    use HasLines;
    use TracksTotals;
}
