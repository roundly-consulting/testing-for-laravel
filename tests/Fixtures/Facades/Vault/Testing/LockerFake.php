<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Testing;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Locker;
use SensitiveParameter;

/**
 * Implements the contract rather than extending the manager, so no implementation stands
 * behind it while it is installed.
 */
final class LockerFake implements Locker
{
    public function open(#[SensitiveParameter] string $code, string $door): bool
    {
        return true;
    }
}
