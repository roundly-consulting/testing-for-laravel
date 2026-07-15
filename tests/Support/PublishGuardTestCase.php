<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\AutoLoadingPackage\AutoLoadingPackageServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\PublishOnlyPackage\PublishOnlyPackageServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\UntimestampedPublishPackage\UntimestampedPublishServiceProvider;

/**
 * Boots the three throwaway providers that back the publish-only guards: one that
 * publishes timestamped and never auto-loads (green), one that auto-loads (red for the
 * autoload guard), and one that publishes an untimestamped destination (red for the
 * publish guard). Registering all three in one app lets each self-test target a provider.
 */
class PublishGuardTestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [
            PublishOnlyPackageServiceProvider::class,
            AutoLoadingPackageServiceProvider::class,
            UntimestampedPublishServiceProvider::class,
        ];
    }
}
