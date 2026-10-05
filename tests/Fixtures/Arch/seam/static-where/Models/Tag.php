<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StaticWhere\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `static::where()` in a static method is `static::query()->where()` by another name: Model's
 * __callStatic forwards it to a query on the class the caller NAMED, so a host swap is ignored.
 */
class Tag extends Model
{
    protected $guarded = [];

    public static function named(string $name): ?self
    {
        return static::where('name', $name)->first();
    }
}
