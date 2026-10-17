<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Support;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Models\Sprocket;

/**
 * A multi-model seam that keeps its key and its default in constants — still a seam: the
 * constants hold the literal and the class, and the method reads both.
 */
final class Catalog
{
    private const string KEY = 'arch.sprocket_model';

    private const DEFAULT = Sprocket::class;

    public static function sprocket(): string
    {
        $model = config(self::KEY, self::DEFAULT);

        return is_string($model) ? $model : self::DEFAULT;
    }
}
