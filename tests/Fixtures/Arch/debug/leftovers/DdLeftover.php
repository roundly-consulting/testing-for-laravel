<?php

declare(strict_types=1);

namespace Fixture\Debug\Leftovers;

final class DdLeftover
{
    public function inspect(string $value): void
    {
        dd($value);
    }
}
