<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support\RedactsSensitiveArguments;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Testing\VaultFake;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;

/**
 * Green: the selective trait hides exactly the marked arguments in its own frame.
 *
 * @method static bool unlock(string $secret, string $label)
 * @method static string encode(string $bytes)
 * @method static string join(string $glue, string ...$parts)
 * @method static string fingerprint(string $key)
 * @method static string label(string $name, int $width = 10)
 * @method static int size()
 */
final class Vault extends Facade
{
    use RedactsSensitiveArguments;

    public static function fake(): VaultFake
    {
        $fake = new VaultFake;

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return VaultManager::class;
    }
}
