<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Concerns;

use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Testing\Support\ProviderMigrationDirectory;

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

        return ProviderMigrationDirectory::locate($source);
    }
}
