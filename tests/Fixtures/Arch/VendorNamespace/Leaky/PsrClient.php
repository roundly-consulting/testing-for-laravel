<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Leaky;

use GuzzleHttp\Psr7\Utils;

/**
 * Imports guzzlehttp/psr7 — a sibling package under the `GuzzleHttp` vendor prefix, with its own
 * PSR-4 root — so Pest's `->not->toUse('GuzzleHttp')` never expands to it.
 */
final class PsrClient
{
    public function body(string $contents): string
    {
        return (string) Utils::streamFor($contents);
    }
}
