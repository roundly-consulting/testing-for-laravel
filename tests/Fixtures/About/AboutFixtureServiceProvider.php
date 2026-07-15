<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\About;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Contributes a single `about` section whose rows are whatever
 * `config('about_fixture.payload')` holds when the command runs. A test can therefore
 * make the section leak a secret, render safe content, or render unrelated content by
 * setting that config before calling `about`.
 */
final class AboutFixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        AboutCommand::add('Testing', function (): array {
            $payload = config('about_fixture.payload', []);

            return is_array($payload) ? array_map(strval(...), $payload) : [];
        });
    }
}
