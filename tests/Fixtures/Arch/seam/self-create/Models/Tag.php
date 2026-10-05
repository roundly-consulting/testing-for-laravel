<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\SelfCreate\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `self::create()` from a static method creates the packaged class, never the host's.
 */
class Tag extends Model
{
    protected $guarded = [];

    public static function make(string $name): self
    {
        return self::create(['name' => $name]);
    }
}
