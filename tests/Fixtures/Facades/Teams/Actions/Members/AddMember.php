<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Members;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;

final class AddMember
{
    public function execute(Team $team, string $email, string $role): void {}
}
