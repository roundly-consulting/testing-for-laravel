<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Support;

use Illuminate\Foundation\Console\AboutCommand;
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Testing\Tests\Fixtures\About\AboutFixtureServiceProvider;

/**
 * Boots the `about`-contributing fixture provider so the secret-capture self-tests have
 * a real section to render. Each test sets `about_fixture.payload` to drive the section
 * into leaking, rendering safe content, or rendering unrelated content.
 */
class AboutSecretsTestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        // AboutCommand keeps its registered sections in static state; clear it so a
        // section registered by an earlier test cannot bleed into this one.
        AboutCommand::flushState();

        parent::setUp();
    }

    protected function packageProviders(): array
    {
        return [AboutFixtureServiceProvider::class];
    }
}
