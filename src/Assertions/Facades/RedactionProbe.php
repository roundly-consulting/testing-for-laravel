<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use SensitiveParameter;

/**
 * The root {@see FacadeRedaction} swaps in behind a facade: it accepts any call and throws
 * straight away, so the trace ends one frame below the facade and nothing real runs.
 *
 * Its own `$arguments` are `#[SensitiveParameter]`, so the only frames that can still show a
 * probe value raw are the facade's own (and whatever it forwards through on the way here).
 *
 * @internal
 */
final class RedactionProbe
{
    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function __call(string $method, #[SensitiveParameter] array $arguments): never
    {
        throw new RedactionProbeReached($method);
    }
}
