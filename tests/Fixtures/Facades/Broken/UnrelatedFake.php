<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken;

/**
 * Records calls, but is not a TeamsManager — a TypeError for anything that injects one.
 */
final class UnrelatedFake
{
    public function assertNothingCreated(): void {}
}
