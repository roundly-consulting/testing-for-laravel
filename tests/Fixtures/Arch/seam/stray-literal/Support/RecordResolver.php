<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StrayLiteral\Support;

/**
 * The seam's legitimate home for the swap literal.
 */
final class RecordResolver
{
    public static function class(): string
    {
        $model = config('arch.record_model');

        return is_string($model) ? $model : '';
    }
}
