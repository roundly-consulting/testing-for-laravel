<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema;

use Illuminate\Support\ServiceProvider;

/**
 * A package whose schema a worker may keep: a parent and a child table joined by a foreign key,
 * and a table whose key counter a migration starts at 100.
 */
final class KeptSchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
