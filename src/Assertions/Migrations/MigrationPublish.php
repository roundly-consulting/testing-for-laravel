<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;

/**
 * The companion to {@see MigrationAutoload}: pins that a provider publishes its
 * migrations *timestamped* under the expected tag, with the source count locked.
 *
 * A publish-only package maps every migration source to a
 * `database_path('migrations/<Y_m_d_His>_<name>.php')` destination, so `vendor:publish`
 * stamps a fresh, ordered filename into the host app. This asserts the tag resolves to
 * exactly $count entries and that every destination is a timestamped migration path —
 * a bare `database_path('migrations')` directory destination fails.
 */
final class MigrationPublish
{
    private const TIMESTAMPED_DESTINATION = '#/migrations/\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+\.php$#';

    public static function assert(string $providerClass, string $tag, int $count): void
    {
        $paths = ServiceProvider::pathsToPublish($providerClass, $tag);

        Assert::assertCount(
            $count,
            $paths,
            "Expected {$providerClass} to publish {$count} migration(s) under tag [{$tag}], found "
            .count($paths).'. Check the tag name and that the provider booted in console.',
        );

        foreach ($paths as $destination) {
            Assert::assertMatchesRegularExpression(
                self::TIMESTAMPED_DESTINATION,
                (string) $destination,
                "Publish destination [{$destination}] under tag [{$tag}] is not a timestamped migration path "
                .'(expected database_path(\'migrations/<Y_m_d_His>_<name>.php\')).',
            );
        }
    }
}
