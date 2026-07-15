<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Expectations;

use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationGraph;
use RoundlyConsulting\Testing\Pest\Plugin;

/**
 * Registers this package's Pest expectations — the canonical, documented API.
 *
 * Reach for `expect($dir)->toHaveRunnableMigrationOrder(...)` everywhere. The
 * expectations are the real surface: they drive the shared assertion
 * implementations directly. The static {@see Assert}
 * entry points exist only as a secondary escape hatch for plain-PHPUnit callers.
 *
 * Registration happens two ways, both shipped:
 *   1. automatically, via the Pest plugin ({@see Plugin});
 *   2. explicitly, by calling {@see self::register()} from a suite's tests/Pest.php.
 *
 * {@see self::register()} is idempotent, so running both is safe.
 */
final class Expectations
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered || ! function_exists('expect')) {
            return;
        }

        self::$registered = true;

        expect()->extend('toHaveRunnableMigrationOrder', function (?int $foreignKeys = null, array $tableResolvers = []): mixed {
            MigrationGraph::forDirectory((string) $this->value, $tableResolvers)
                ->assertRunnable($foreignKeys);

            return $this;
        });
    }

    /**
     * Whether the expectations have been registered in this process.
     */
    public static function registered(): bool
    {
        return self::$registered;
    }

    /**
     * Reset the idempotency guard. Intended for this package's own tests, which need
     * to prove {@see self::register()} both registers and no-ops on a second call.
     */
    public static function flush(): void
    {
        self::$registered = false;
    }
}
