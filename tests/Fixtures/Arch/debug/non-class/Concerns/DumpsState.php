<?php

declare(strict_types=1);

namespace Fixture\Debug\NonClass\Concerns;

// A trait with a deliberate dump() — exemptible by name like any class.
trait DumpsState
{
    public function show(): void
    {
        dump($this);
    }
}
