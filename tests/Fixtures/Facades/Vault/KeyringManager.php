<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Keyring;
use SensitiveParameter;

/**
 * Drifts from its contract three ways: open() drops the contract's attribute, seal() adds one
 * the contract lacks, and debug() takes a secret the contract does not declare at all.
 */
final class KeyringManager implements Keyring
{
    public function open(string $pin, string $label): bool
    {
        return $pin === $label;
    }

    public function seal(#[SensitiveParameter] string $token): string
    {
        return strrev($token);
    }

    public function debug(#[SensitiveParameter] string $secret): string
    {
        return strlen($secret).' bytes';
    }
}
