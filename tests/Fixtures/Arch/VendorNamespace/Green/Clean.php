<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Green;

use Illuminate\Support\Str;

/**
 * Mentions GuzzleHttp\Client and App\Models\User only where they are not code: this docblock,
 * a comment, a string literal and member names. `Acme\Thing` below is relative to this file's
 * own namespace, not the `Acme` vendor. Excluded from Pint (pint.json), which rewrites relative
 * names.
 */
final class Clean
{
    use Concerns\HasAcme;

    public function run(object $client): string
    {
        // new \GuzzleHttp\Client() — commented out, so not code.
        $class = 'GuzzleHttp\Client';

        $client->guzzleHttp();
        $client::App();

        $anonymous = new class
        {
            use Concerns\HasAcme;
        };

        return Str::lower($class).Acme\Thing::class.namespace\Acme\Thing::class.$anonymous::class;
    }
}
