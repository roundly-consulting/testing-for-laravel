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
