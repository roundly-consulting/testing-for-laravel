<?php

declare(strict_types=1);

namespace Fixture\Debug\NonClass\Support;

// An enum with a deliberate dd() — exemptible by name like any class.
enum Inspector: string
{
    case Halt = 'halt';

    public function stop(): never
    {
        dd($this);
    }
}
