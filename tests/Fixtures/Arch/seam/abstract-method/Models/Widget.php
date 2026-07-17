<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\AbstractMethod\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An abstract method declaration has no braced body. If tracking mistook its `;` for one, or
 * left a frame half-open, the static method below would be misread as instance-scoped and the
 * ban would silently stop firing — a false negative dressed as a pass.
 */
abstract class Widget extends Model
{
    protected $guarded = [];

    abstract public function label(): string;

    public static function firstNamed(string $name): mixed
    {
        return static::query()->where('name', $name)->first();
    }
}
