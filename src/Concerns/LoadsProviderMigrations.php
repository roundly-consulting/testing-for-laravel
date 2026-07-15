<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Concerns;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * Loads a package's migrations by pointing at its *service provider*, never by
 * naming an individual migration file.
 *
 * The provider's own directory is located by reflection, so the same call works for
 * a path-symlinked sibling and a VCS install alike. Naming a sibling package's
 * migration file by hand — the mistake that broke five packages' suites — is
 * impossible through this API: you can only hand it a provider class or a literal
 * directory.
 *
 * @method void loadMigrationsFrom(array<int, string>|string $paths)
 */
trait LoadsProviderMigrations
{
    /**
     * @param  list<class-string<ServiceProvider>|string>  $sources  provider classes (resolved
     *                                                               by reflection) or literal
     *                                                               migration directories
     */
    protected function loadMigrationSources(array $sources): void
    {
        foreach ($sources as $source) {
            $this->loadMigrationsFrom(self::migrationDirectoryFor($source));
        }
    }

    /**
     * @param  class-string<ServiceProvider>|string  $source
     */
    protected static function migrationDirectoryFor(string $source): string
    {
        if (! class_exists($source)) {
            Assert::assertDirectoryExists(
                $source,
                "Migration source `{$source}` is neither a provider class nor a directory.",
            );

            return $source;
        }

        $fileName = (new ReflectionClass($source))->getFileName();

        Assert::assertIsString($fileName, "Could not locate the file for provider `{$source}`.");

        // Walk up from the provider file (typically src/) looking for the package's
        // database/migrations directory.
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

        Assert::fail("Could not locate a database/migrations directory for provider `{$source}`.");
    }
}
