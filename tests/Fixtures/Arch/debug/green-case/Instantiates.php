<?php

declare(strict_types=1);

namespace Fixture\Debug\GreenCase;

// Case-insensitive matching must not turn class names into calls: `new Dump(...)` builds an
// object, and `Ray::make()` is a static method — neither is the global debugger.
final class Instantiates
{
    public function build(mixed $value): array
    {
        return [new Dump($value), new \Fixture\Ray($value), Ray::make($value), new class {}];
    }
}
