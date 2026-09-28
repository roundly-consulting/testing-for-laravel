<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Members\AddMember;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;

/**
 * A sub-accessor two hops from the manager: `Teams::for($team)->members()->add(...)`.
 * AddMember is referenced nowhere else on the surface.
 */
final readonly class MembersAccessor
{
    public function __construct(private Container $container, private Team $team) {}

    public function add(string $email, string $role = 'member'): void
    {
        $this->container->make(AddMember::class)->execute($this->team, $email, $role);
    }
}
