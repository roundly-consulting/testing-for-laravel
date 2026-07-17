<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * The control: shares the namespace but not the prefix, and is final. It must never be
 * reported as shadowed — a detector that flags this is matching the namespace, not the
 * exemption.
 */
final class Unrelated
{
    public function ok(): bool
    {
        return true;
    }
}
