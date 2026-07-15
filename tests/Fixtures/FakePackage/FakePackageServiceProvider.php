<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\FakePackage;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

/**
 * A throwaway "package" provider used to prove {@see PackageTestCase}
 * loads a provider's migrations by class (via reflection) rather than by filename.
 * It deliberately does NOT auto-load its migrations — the test case does.
 */
final class FakePackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
