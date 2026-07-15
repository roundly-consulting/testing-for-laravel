<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use Illuminate\Database\Eloquent\Model;

/**
 * A *broken* action: it hard-codes the packaged {@see Record} class, so it ignores the
 * host swap and creates the row as the packaged class. The model-swap assertion must go
 * red on this (the #3/#28/#31 shape: one hard-coded call site beside an honoured config).
 */
final class CreateRecordHardCoded
{
    public static function run(string $name): Model
    {
        return Record::query()->create(['name' => $name]);
    }
}
