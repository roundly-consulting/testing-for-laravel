<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StaticFirstOrCreate\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The permissions #34 "findOrCreate" shape, spelled through the forwarded builder method.
 */
class Tag extends Model
{
    protected $guarded = [];

    public static function findOrMake(string $name): self
    {
        return static::firstOrCreate(['name' => $name]);
    }
}
