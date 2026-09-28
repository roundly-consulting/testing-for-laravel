<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Contracts;

/**
 * The facade root is this contract. Its file references no action — the implementation
 * the container binds to it does.
 */
interface Ledger
{
    public function record(int $amount): void;

    public function balance(): int;
}
