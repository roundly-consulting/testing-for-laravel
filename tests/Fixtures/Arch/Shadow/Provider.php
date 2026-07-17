<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * The legitimately exempted extension point: a driver a host subclasses. Non-final on
 * purpose, and named explicitly in the exemption list — this is the entry an author means.
 */
class Provider
{
    public function name(): string
    {
        return 'provider';
    }
}
