<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Testing;

use PHPUnit\Framework\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

/**
 * A subtype of the manager, so constructor-injected TeamsManager keeps working under it.
 */
class TeamsFake extends TeamsManager
{
    /**
     * @var list<string>
     */
    private array $created = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function create(string $name, array $options = []): Team
    {
        $this->created[] = $name;

        return new Team(['name' => $name]);
    }

    public function assertCreated(string $name): void
    {
        Assert::assertContains($name, $this->created);
    }

    public function assertNothingCreated(): void
    {
        Assert::assertSame([], $this->created);
    }
}
