<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\LedgerServiceProvider;

/**
 * Boots an app with the Ledger contract bound to its manager, so the facade assertions that
 * need the container (`toBeFakeable`, the concrete-root half of `toReachEveryAction`) run
 * against a real binding.
 */
class FacadesTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [LedgerServiceProvider::class];
    }
}
