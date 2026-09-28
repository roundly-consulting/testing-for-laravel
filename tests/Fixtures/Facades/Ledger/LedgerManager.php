<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Actions\ComputeBalance;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Actions\RecordEntry;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Contracts\Ledger;

final readonly class LedgerManager implements Ledger
{
    public function __construct(private Container $container) {}

    public function record(int $amount): void
    {
        $this->container->make(RecordEntry::class)->execute($amount);
    }

    public function balance(): int
    {
        return $this->container->make(ComputeBalance::class)->execute();
    }
}
