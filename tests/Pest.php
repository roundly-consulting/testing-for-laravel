<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Expectations\Expectations;

// Register this package's expectations explicitly. In a consumer suite the Pest
// plugin (extra.pest.plugins) does this automatically; register() is idempotent.
Expectations::register();

/**
 * Absolute path to a migration-set fixture directory under tests/Fixtures.
 */
function fixturePath(string $path): string
{
    return __DIR__.'/Fixtures/'.$path;
}

/**
 * Absolute path under tests/Fixtures/config-contract.
 */
function configContractFixture(string $path): string
{
    return __DIR__.'/Fixtures/config-contract/'.$path;
}

/**
 * Whether `TESTING_DB_DRIVER` is exported on this CI leg.
 *
 * The matrix's own driver tests cannot force this var: Laravel's env repository is
 * immutable, so a var already present in the process environment can be neither cleared
 * nor overwritten. Each branch is therefore asserted on the leg where it is real and
 * *skipped visibly* on the other — never asserted against the ambient and passed off as
 * proof, which is what "defaults to sqlite" was doing before the pgsql leg became real.
 */
function driverEnvIsSet(): bool
{
    $driver = env('TESTING_DB_DRIVER');

    return is_string($driver) && $driver !== '';
}
