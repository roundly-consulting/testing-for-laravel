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
     * Resolve one migration source to a directory.
     *
     * The literal-directory branch checks with `is_dir()` and only reaches for PHPUnit on
     * the **failure** path. It used to call `Assert::assertDirectoryExists()`, which is a
     * passing assertion when the directory is there — and `defineDatabaseMigrations()`
     * runs once per test, so that silently added **+1 assertion to every test in the
     * suite**. Any test written `->throwsNoExceptions()` declares
     * `expectNotToPerformAssertions()`, so the loader's own bookkeeping made it RISKY
     * ("not expected to perform assertions but performed 1 assertion"), and
     * `failOnRisky="true"` turned that into a red suite — on 11 packages, over code that
     * was correct.
     *
     * A setup precondition is not something the test under it asserted: verifying the
     * directory must cost the test zero assertions. `Assert::fail()` still throws
     * loudly (and identically to {@see ProviderMigrationDirectory}'s own failures) when
     * the directory really is missing, and a thrown failure's assertion count is moot.
     *
     * @param  class-string<ServiceProvider>|string  $source
     */
    protected static function migrationDirectoryFor(string $source): string
    {
        if (class_exists($source)) {
            return ProviderMigrationDirectory::locate($source);
        }

        if (! is_dir($source)) {
            Assert::fail("Migration source `{$source}` is neither a provider class nor a directory.");
        }

        return $source;
    }
}
