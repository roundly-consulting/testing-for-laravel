<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Expectations;

use Closure;
use RoundlyConsulting\Testing\Arch\SwappableModels;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Assertions\AboutSecrets;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationAutoload;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationGraph;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationPublish;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Testing\Assertions\ModelSwap;
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

        expect()->extend('toApplyOnConnection', function (string $connection): mixed {
            MigrationRunner::applyOnConnection((string) $this->value, $connection);

            return $this;
        });

        expect()->extend('toRejectBrokenOrderOnConnection', function (Closure $reorder, string $connection): mixed {
            MigrationRunner::brokenOrderIsRejectedOnConnection((string) $this->value, $reorder, $connection);

            return $this;
        });

        expect()->extend('toNotAutoLoadMigrations', function (?string $migrationsDir = null): mixed {
            MigrationAutoload::assert((string) $this->value, $migrationsDir);

            return $this;
        });

        expect()->extend('toPublishMigrationsTimestamped', function (string $tag, int $count): mixed {
            MigrationPublish::assert((string) $this->value, $tag, $count);

            return $this;
        });

        expect()->extend('toSatisfyConfigContract', function (string|array $srcDirs, array $options = []): mixed {
            ConfigContract::assert((string) $this->value, $srcDirs, null, $options);

            return $this;
        });

        expect()->extend('toLeakNoSecrets', function (array $secrets, array $mustRender): mixed {
            AboutSecrets::assert((string) $this->value, $secrets, $mustRender);

            return $this;
        });

        expect()->extend('toHonourModelSwap', function (string $subclass, Closure $exercise): mixed {
            ModelSwap::assert((string) $this->value, $subclass, $exercise);

            return $this;
        });

        expect()->extend('toBeSwappableVia', function (string $configKey): mixed {
            SwappableModels::assertEntry((string) $this->value, $configKey);

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
