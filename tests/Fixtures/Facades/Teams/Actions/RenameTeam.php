<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;

final class RenameTeam
{
    public function execute(Team $team, string $name): Team
    {
        $team->setAttribute('name', $name);

        return $team;
    }
}
