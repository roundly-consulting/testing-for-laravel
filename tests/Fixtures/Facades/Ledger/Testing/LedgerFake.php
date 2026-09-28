<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Testing;

use PHPUnit\Framework\Assert;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Contracts\Ledger;

/**
 * Implements the contract directly — no package class stands behind it.
 */
final class LedgerFake implements Ledger
{
    /**
     * @var list<int>
     */
    private array $recorded = [];

    public function record(int $amount): void
    {
        $this->recorded[] = $amount;
    }

    public function balance(): int
    {
        return array_sum($this->recorded);
    }

    public function assertRecorded(int $amount): void
    {
        Assert::assertContains($amount, $this->recorded);
    }
}
