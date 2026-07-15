<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Locks;

use Illuminate\Support\ServiceProvider;

/**
 * A throwaway "package" that owns the `lock_widgets` table the lock-recorder self-tests
 * run against. It does not auto-load its migration — the test case does, by class.
 */
final class LockFixtureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }
}
