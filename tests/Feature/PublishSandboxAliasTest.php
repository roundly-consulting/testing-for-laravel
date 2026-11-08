<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Tests\Support\AliasedPublishSandboxTestCase;

uses(AliasedPublishSandboxTestCase::class);

it('keeps the sandbox when the using class overrides getPackageProviders() through an alias', function (): void {
    expect(AliasedPublishSandboxTestCase::$calls)->toBeGreaterThan(0);

    $this->assertPublishesIntoSandbox('sandboxed-config', 'sandboxed-seeders', 'sandboxed-migrations');
});
