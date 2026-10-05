<?php

declare(strict_types=1);

namespace Fixture\Debug\NonClass\Support;

// A clean sibling class: under the old class-only scan, it alone made a namespace exemption
// "match" while the trait and enum beside it were still reported.
final class Plain
{
    public function value(): int
    {
        return 1;
    }
}
