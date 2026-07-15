<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Pest;

use Pest\Contracts\Plugins\Bootable;
use RoundlyConsulting\Testing\Expectations\Expectations;

/**
 * Auto-registers this package's Pest expectations the moment the package is
 * installed — wired through composer's `extra.pest.plugins`. Pest boots every
 * plugin implementing {@see Bootable}; here that registers the expectations so a
 * consumer needs zero setup in tests/Pest.php.
 *
 * Suites that disable plugin discovery call {@see Expectations::register()}
 * directly; it is idempotent, so both paths coexist.
 */
final class Plugin implements Bootable
{
    public function boot(): void
    {
        Expectations::register();
    }
}
