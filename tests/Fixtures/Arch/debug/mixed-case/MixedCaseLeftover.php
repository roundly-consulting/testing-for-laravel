<?php

declare(strict_types=1);

namespace Fixture\Debug\MixedCase;

// PHP function and method names are case-insensitive: each of these runs the debugger. Excluded
// from Pint (pint.json), whose native_function_casing would lowercase the very case under test.
final class MixedCaseLeftover
{
    public function inspect(mixed $value, mixed $query): void
    {
        DD($value);
        Var_Dump($value);
        \Print_R($value);
        $query->DDRawSql();
    }
}
