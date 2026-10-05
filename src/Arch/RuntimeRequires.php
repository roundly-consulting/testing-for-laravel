<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use PHPUnit\Framework\Assert;

/**
 * The runtime dependency policy expressed as a test.
 *
 * A roundly package's `require` block (what a host app is forced to install) may hold
 * only PHP itself and the other Composer **platform packages** (extensions, `lib-*`,
 * `composer-runtime-api`, `composer-plugin-api`, `php-64bit`, … — they name the platform and
 * install no code), official Laravel (`illuminate/*`, `laravel/*`), official Symfony
 * (`symfony/*`), and our own `roundly-consulting/*` packages — plus any explicit
 * `$alsoAllow` a specific package justifies. A stray third-party vendor in `require`
 * ships transitively into every consumer; catching it in the package's own suite keeps
 * the policy from rotting between reviews.
 *
 * This is the assertion behind {@see ArchPresets::runtimeRequireIsWhitelisted()}.
 */
final class RuntimeRequires
{
    private const WHITELIST = '#^(php$|ext-|illuminate/|laravel/|symfony/|roundly-consulting/)#';

    /** Composer's own platform-package pattern (`PlatformRepository::PLATFORM_PACKAGE_REGEX`). */
    private const PLATFORM = '{^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[a-z0-9](?:[_.-]?[a-z0-9]+)*|composer(?:-(?:plugin|runtime)-api)?)$}iD';

    /**
     * @param  list<string>  $alsoAllow  extra `require` keys this package explicitly permits
     */
    public static function assert(string $composerJson, array $alsoAllow = []): void
    {
        Assert::assertFileExists($composerJson, "composer.json not found: {$composerJson}");

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($composerJson), true, flags: JSON_THROW_ON_ERROR);

        $require = $data['require'] ?? [];

        Assert::assertIsArray($require, "composer.json 'require' is not an object.");

        $disallowed = [];

        foreach (array_keys($require) as $package) {
            $package = (string) $package;

            if (in_array($package, $alsoAllow, true)) {
                continue;
            }

            if (preg_match(self::WHITELIST, $package) !== 1 && preg_match(self::PLATFORM, $package) !== 1) {
                $disallowed[] = $package;
            }
        }

        sort($disallowed);

        Assert::assertSame(
            [],
            $disallowed,
            "composer.json 'require' holds packages outside the runtime dependency policy: "
            .implode(', ', $disallowed)
            .'. Runtime deps may only be php and other Composer platform packages (ext-*, lib-*, composer-*-api, '
            .'php-64bit, …), illuminate/*, laravel/*, symfony/*, roundly-consulting/*, or an explicit $alsoAllow '
            .'entry — reimplement natively or move it to require-dev.',
        );
    }
}
