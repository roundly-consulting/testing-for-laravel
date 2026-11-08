<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Tests\Support\ConfigOnlyPublishSandboxTestCase;

uses(ConfigOnlyPublishSandboxTestCase::class);

it('moves only the paths the test case names', function (): void {
    expect(config_path())->toBe($this->publishSandbox('config'))
        ->and(database_path())->not->toStartWith($this->publishSandbox())
        ->and(app_path())->not->toStartWith($this->publishSandbox())
        ->and($this->publishSandbox('database'))->not->toBeDirectory();

    $this->assertPublishesIntoSandbox('sandboxed-config');
});

it('still pins a tag that writes outside the sandboxed paths', function (): void {
    // database/ is not sandboxed here, so the seeder lands in the skeleton — the assertion says so.
    expect(fn () => $this->assertPublishesIntoSandbox('sandboxed-seeders'))
        ->toThrow(AssertionFailedError::class, 'sandboxed-seeders');
});

it('seeds the sandbox before the providers boot', function (): void {
    expect($this->publishSandbox('config/sandboxed.php'))->toBeFile()
        ->and(require $this->publishSandbox('config/sandboxed.php'))->toBe(['host' => true]);
});
