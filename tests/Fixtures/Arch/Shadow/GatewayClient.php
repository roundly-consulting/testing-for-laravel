<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * Shadowed by `Gateway`, but **final** — it satisfies the rule the exemption stopped
 * enforcing. This is the fleet's normal case: 27 of the 28 shadowed classes measured
 * across 12 packages look exactly like this. It must stay green, with no declaration and
 * no ceremony, or the check would cost 12 packages a change to describe 1 real bug.
 */
final class GatewayClient
{
    public function send(): string
    {
        return 'sent';
    }
}
