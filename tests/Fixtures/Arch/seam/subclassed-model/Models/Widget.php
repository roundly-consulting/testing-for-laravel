<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\SubclassedModel\Models;

/**
 * A real seam bypass on a model that does NOT name Model in its extends clause. Gating
 * the ban on a token-level `extends Model` match would let this through; resolving the
 * parent chain by reflection catches it.
 */
class Widget extends BaseWidget
{
    public static function pending(): mixed
    {
        return self::query()->get();
    }
}
