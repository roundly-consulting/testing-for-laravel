<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use Illuminate\Database\Eloquent\Model;

/**
 * Returns a {@see GrandchildRecord} — an `instanceof HostRecord`, but a different
 * concrete class. Proves the assertion checks the concrete class, not merely `instanceof`.
 */
final class CreateGrandchildRecord
{
    public static function run(string $name): Model
    {
        return GrandchildRecord::query()->create(['name' => $name]);
    }
}
