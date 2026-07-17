<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Solo;

/**
 * Lives under the `Solo` namespace and is shadowed by the `Solo` **class** next to it —
 * `...\Arch\Solo\Client` starts with `...\Arch\Solo`. Non-final, so it must be reported.
 */
class Client
{
    public function send(): string
    {
        return 'sent';
    }
}
