<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\SeedingSchema;

use Illuminate\Support\ServiceProvider;

/**
 * A package whose migrations write rows — the schema a worker must never keep, or the second
 * test would start without them.
 */
final class SeedingSchemaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
