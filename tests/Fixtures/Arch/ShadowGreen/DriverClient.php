<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\ShadowGreen;

/**
 * Prefix-shadowed by `Driver`, and final — so `finalByDefault` plus its shadow recovery
 * must both pass. This namespace is the green smoke for the wiring: it proves the
 * recovery composes onto the preset without failing a package that is simply correct.
 */
final class DriverClient
{
    public function send(): string
    {
        return 'sent';
    }
}
