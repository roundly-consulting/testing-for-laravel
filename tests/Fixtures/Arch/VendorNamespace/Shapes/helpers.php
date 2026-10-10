<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Shapes;

// A file that declares no class is scanned too.
function zero(): mixed
{
    return \Acme\Money::zero();
}
