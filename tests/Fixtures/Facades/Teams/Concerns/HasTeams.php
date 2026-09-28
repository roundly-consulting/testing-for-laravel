<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Concerns;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

trait HasTeams
{
    public function createTeam(string $name): Team
    {
        return app(TeamsManager::class)->create($name);
    }
}
