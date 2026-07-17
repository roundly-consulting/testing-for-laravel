<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * The victim. Nobody exempted this class, and it is **not** final — so `finalByDefault`
 * must flag it. It does not, because its FQCN starts with the string `...\Shadow\Provider`
 * and Pest matches exemptions by prefix. This is the transport client shape that hid
 * `StripeClient` and `GoogleClient` behind `Stripe` and `Google` in purchases.
 */
class ProviderClient
{
    public function send(): string
    {
        return 'sent';
    }
}
