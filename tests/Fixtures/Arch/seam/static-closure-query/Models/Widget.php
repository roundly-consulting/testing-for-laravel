<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StaticClosureQuery\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Guard the guard for the instance-method fix: the closure is not itself static, but it is
 * declared inside a static method, so it has no `$this` either and `self::query()` in it can
 * still miss a host swap. Clearing the ban on "the innermost function is not static" would
 * hand it a silent false negative — nesting has to taint outward.
 */
class Widget extends Model
{
    protected $guarded = [];

    public static function pending(): mixed
    {
        return array_map(static fn (string $name): mixed => self::query()->where('name', $name)->first(), ['a']);
    }
}
