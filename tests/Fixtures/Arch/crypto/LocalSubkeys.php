<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Crypto;

final class LocalSubkeys
{
    public function derive(string $key, string $purpose): string
    {
        return hash_hkdf('sha256', $key, 32, $purpose);
    }
}
