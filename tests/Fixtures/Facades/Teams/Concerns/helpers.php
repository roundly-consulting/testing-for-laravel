<?php

declare(strict_types=1);

// A file with no class in a scanned namespace: nothing to attribute a reference to, so the
// facade preset skips it rather than inventing a violator.

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\CreateTeam;

function teams_fixture_helper(): string
{
    return CreateTeam::class;
}
