<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch;

use Illuminate\Database\Eloquent\Model;

/**
 * A correctly-seamed swappable model: NOT final, so a host may subclass it.
 */
class SwappableModel extends Model
{
    protected $guarded = [];
}
