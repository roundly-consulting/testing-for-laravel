<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\SelfQuery\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `self::query()` binds to the class the code named, not the host-configured subclass —
 * the same seam bypass as `static::query()`. The seam preset must go red on this.
 */
class Widget extends Model
{
    protected $guarded = [];

    public static function all($columns = ['*']): mixed
    {
        return self::query()->get($columns);
    }
}
