<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Testing;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Support\GadgetModel;

/** A testing helper that asserts against the configured model. */
final class GadgetAssertions
{
    public static function count(): int
    {
        return GadgetModel::class()::query()->count();
    }
}
