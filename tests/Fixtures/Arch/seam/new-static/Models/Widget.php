<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\NewStatic\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `new static` instantiates the class the code named, not the host-configured subclass —
 * a host swap is ignored. The seam preset must go red on this.
 */
class Widget extends Model
{
    protected $guarded = [];

    public static function make(): static
    {
        return new static;
    }
}
