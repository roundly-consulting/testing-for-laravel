<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\RenameTeam;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;

/**
 * A model-scoped handle: `Teams::for($team)`. One hop from the manager.
 */
final readonly class TeamHandle
{
    public function __construct(private Container $container, private Team $team) {}

    public function members(): MembersAccessor
    {
        return new MembersAccessor($this->container, $this->team);
    }

    public function rename(string $name): Team
    {
        return $this->container->make(RenameTeam::class)->execute($this->team, $name);
    }
}
