<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\FakePackage\FakePackageServiceProvider;

/**
 * A {@see PackageTestCase} whose default connection is a **file-backed** SQLite database.
 *
 * This exists to make the drop-based reset provable on **every** leg, not just the pgsql one.
 * The reset only does anything where the database outlives the connection, and
 * `usesSqliteInMemoryDatabaseConnection()` is what routes `:memory:` to Testbench's untouched
 * teardown. A file database is not in-memory, so it takes the *real-engine* branch — the same
 * branch Postgres takes — while needing no database service at all.
 *
 * Without this, the coverage leg (SQLite matrix) never executes the drop, and the branch that
 * fixes the whole 22-failure class would be exercised only on a job that measures no coverage.
 */
class FileDatabaseTestCase extends PackageTestCase
{
    private string $databaseFile = '';

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->databaseFile !== '' && file_exists($this->databaseFile)) {
            unlink($this->databaseFile);
        }
    }

    /**
     * The file the current test's database lives in — the thing that survives teardown and
     * makes this a real state-reset problem.
     */
    public function databaseFile(): string
    {
        return $this->databaseFile;
    }

    protected function packageProviders(): array
    {
        return [FakePackageServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [FakePackageServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if ($this->databaseFile === '') {
            $this->databaseFile = (string) tempnam(sys_get_temp_dir(), 'roundly-testing-').'.sqlite';
            touch($this->databaseFile);
        }

        $app->make('config')->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => $this->databaseFile,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}
