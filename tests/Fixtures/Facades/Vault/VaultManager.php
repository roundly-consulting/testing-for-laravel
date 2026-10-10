<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault;

use SensitiveParameter;

/**
 * The root behind every Vault facade. Four methods take a secret: one alongside a harmless
 * argument, one alone, one as a variadic and one static. The rest take harmless arguments
 * only, or none.
 */
class VaultManager
{
    public function unlock(#[SensitiveParameter] string $secret, string $label): bool
    {
        return hash_equals($secret, $label);
    }

    public function encode(#[SensitiveParameter] string $bytes): string
    {
        return bin2hex($bytes);
    }

    public function join(string $glue, #[SensitiveParameter] string ...$parts): string
    {
        return implode($glue, $parts);
    }

    public static function fingerprint(#[SensitiveParameter] string $key): string
    {
        return hash('sha256', $key);
    }

    public function label(string $name, int $width = 10): string
    {
        return str_pad($name, $width);
    }

    public function size(): int
    {
        return 0;
    }
}
