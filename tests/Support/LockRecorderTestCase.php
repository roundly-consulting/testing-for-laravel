<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\Locks\LockFixtureServiceProvider;

/**
 * Boots the throwaway lock-fixture package (owning the `lock_widgets` table) and clears
 * the lock registry before every test so the recorder starts blind.
 */
class LockRecorderTestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        LockRecorder::flush();
    }

    protected function packageProviders(): array
    {
        return [LockFixtureServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [LockFixtureServiceProvider::class];
    }
}
