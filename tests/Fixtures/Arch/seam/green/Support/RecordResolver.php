<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\Green\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The seam: the ONLY place the swap config literal is read, and the correct way to
 * query it — resolve the configured class-string first, then query it. `self::class()`
 * is a method call returning the host-configured class, so `self::class()::query()` is
 * never the banned `self::query()`.
 */
final class RecordResolver
{
    public static function class(): string
    {
        $model = config('arch.record_model');

        return is_string($model) ? $model : '';
    }

    public static function query(): Builder
    {
        return self::class()::query();
    }
}
