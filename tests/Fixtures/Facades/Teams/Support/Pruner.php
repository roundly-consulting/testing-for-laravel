<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Support;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Maintenance\PruneTeams;

/**
 * Held by the manager in a property and never returned — invisible to the return-type
 * walk, so PruneTeams is reachable only when this class is passed through `$via`.
 */
final readonly class Pruner
{
    public function __construct(private Container $container) {}

    public function run(): int
    {
        return $this->container->make(PruneTeams::class)->execute();
    }
}
