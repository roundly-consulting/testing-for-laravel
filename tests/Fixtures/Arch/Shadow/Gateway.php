<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * A second exempted extension point, used to prove the compliant path: it shadows
 * {@see GatewayClient}, which is final and therefore breaks nothing.
 */
class Gateway
{
    public function open(): string
    {
        return 'open';
    }
}
