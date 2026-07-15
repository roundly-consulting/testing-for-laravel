<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Crypto;

final class LocalVerifier
{
    public function verify(string $data, string $signature, mixed $key): bool
    {
        return openssl_verify($data, $signature, $key) === 1;
    }
}
