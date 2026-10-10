<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts;

use SensitiveParameter;

interface Locker
{
    public function open(#[SensitiveParameter] string $code, string $door): bool;
}
