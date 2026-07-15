<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Support;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * Locates a package's `database/migrations` directory from its *service provider*
 * class, by reflection — never by naming a migration file.
 *
 * The provider file is found via {@see ReflectionClass}, then the walk climbs up to
 * six directories looking for `database/migrations`. This resolves identically for a
 * path-symlinked sibling and a VCS install, and makes "name a sibling's migration
 * file" — the mistake that broke five packages — impossible to express.
 */
final class ProviderMigrationDirectory
{
    /**
     * @param  class-string<ServiceProvider>|string  $providerClass
     */
    public static function locate(string $providerClass): string
    {
        if (! class_exists($providerClass)) {
            Assert::fail("Provider class `{$providerClass}` does not exist.");
        }

        $fileName = (new ReflectionClass($providerClass))->getFileName();

        if ($fileName === false) {
            Assert::fail("Could not locate the file for provider `{$providerClass}`.");
        }

        $directory = dirname($fileName);

        for ($depth = 0; $depth < 6; $depth++) {
            $candidate = $directory.'/database/migrations';

            if (is_dir($candidate)) {
                return $candidate;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        Assert::fail("Could not locate a database/migrations directory for provider `{$providerClass}`.");
    }
}
