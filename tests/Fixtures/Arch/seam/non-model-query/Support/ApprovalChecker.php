<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\NonModelQuery\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * The false positive that condemned the ungated ban, reduced to its shape.
 *
 * This class is NOT a model. It resolves the swappable model correctly, through the seam
 * (`self::class()::query()`), and its own private `query()` is just a static helper that
 * happens to share Eloquent's method name. `self::query()` here binds to the helper below
 * — there is no late static binding to a model, and nothing for a host swap to miss.
 *
 * The ban matched the bare token `self::query(` and flagged this on two packages
 * (approvals' ApprovalChecker, and jwt). The preset ships as an `it()` case with no
 * `->ignoring()` escape, so that verdict was unappealable on correct code.
 */
final class ApprovalChecker
{
    public static function class(): string
    {
        $model = config('arch.record_model');

        return is_string($model) ? $model : '';
    }

    public static function pending(): mixed
    {
        return self::query()->where('state', 'pending')->get();
    }

    private static function query(): Builder
    {
        return self::class()::query();
    }
}
