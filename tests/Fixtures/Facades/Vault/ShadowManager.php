<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault;

use SensitiveParameter;

final class ShadowManager
{
    public function swap(#[SensitiveParameter] string $secret): string
    {
        return strrev($secret);
    }
}
