<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

/**
 * Opts in to a kept schema on a file-backed SQLite database — an engine the harness does not
 * keep, so the drop-per-test reset must stay in charge.
 */
class FileDatabaseKeptSchemaTestCase extends FileDatabaseTestCase
{
    protected function keepsMigratedSchema(): bool
    {
        return true;
    }
}
