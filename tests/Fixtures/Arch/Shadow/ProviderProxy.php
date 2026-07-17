<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * A second, equally invisible victim of the same exemption. It exists so the failure
 * message is held to naming **every** class it found: reporting one of two would send a
 * package back for a second round on a defect it had already been told about.
 */
class ProviderProxy
{
    public function proxy(): string
    {
        return 'proxied';
    }
}
