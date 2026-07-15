<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\FakePackage\FakePackageServiceProvider;

/**
 * A {@see PackageTestCase} that overrides nothing but the required providers — proves
 * the default `migrationSources()` / `configBeforeBoot()` hooks and the empty
 * before-boot windows boot cleanly.
 */
class MinimalPackageTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [FakePackageServiceProvider::class];
    }
}
