<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Testing;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;

/**
 * A fake as the fleet writes them: a subtype of the manager that records — and whose override
 * drops the attribute, which the facade frame must survive.
 */
final class VaultFake extends VaultManager
{
    /**
     * @var list<array{string, string}>
     */
    public array $unlocked = [];

    public function unlock(string $secret, string $label): bool
    {
        $this->unlocked[] = [$secret, $label];

        return true;
    }
}
