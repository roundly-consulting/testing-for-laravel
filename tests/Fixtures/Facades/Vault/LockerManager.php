<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Locker;
use SensitiveParameter;

/**
 * Marks what its contract marks. The constructor's secret is no drift: no facade call reaches a
 * constructor.
 */
final readonly class LockerManager implements Locker
{
    public function __construct(#[SensitiveParameter] private string $masterCode = '') {}

    public function open(#[SensitiveParameter] string $code, string $door): bool
    {
        return $code === $this->masterCode || $code === $door;
    }
}
