<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Internal\RecordAudit;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;

final readonly class CreateTeam
{
    public function __construct(private RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function execute(string $name, array $options = []): Team
    {
        $this->audit->execute('created');

        return new Team(['name' => $name, ...$options]);
    }
}
