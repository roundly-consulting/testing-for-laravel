<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StaticQuery\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The permissions #34 bug: a helper resolves the model through `static::query()`, which
 * late-static-binds to the class the code named, not the host-configured subclass — so
 * a host swap is silently ignored. The seam preset must go red on this.
 */
class Widget extends Model
{
    protected $guarded = [];

    public static function firstNamed(string $name): mixed
    {
        return static::query()->where('name', $name)->first();
    }
}
