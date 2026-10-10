<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts;

use SensitiveParameter;

interface Keyring
{
    public function open(#[SensitiveParameter] string $pin, string $label): bool;

    public function seal(string $token): string;
}
