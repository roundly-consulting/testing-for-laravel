<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Tests\Support\PublishSandboxTestCase;

uses(PublishSandboxTestCase::class);

/**
 * The skeleton every parallel process boots from — the one place a publish must never land.
 */
function sharedSkeleton(): string
{
    return realpath(base_path()) ?: base_path();
}

it('publishes into the sandbox, never the shared skeleton', function (): void {
    $root = $this->publishSandbox();

    expect($root)->toStartWith(rtrim(sys_get_temp_dir(), '/').'/')
        ->and($root)->toBeDirectory()
        ->and(config_path())->toBe($root.'/config')
        ->and(database_path())->toBe($root.'/database')
        ->and(app_path())->toBe($root.'/app')
        ->and(config_path())->not->toStartWith(sharedSkeleton())
        ->and(database_path('migrations'))->toBeDirectory()
        ->and(app_path())->toBeDirectory();

    $this->assertPublishesIntoSandbox('sandboxed-config', 'sandboxed-seeders', 'sandboxed-migrations');
});

it('moves the paths before the providers register, so a register-time destination lands in it', function (): void {
    // The provider maps `config_path('sandboxed.php')` inside register(): a sandbox set in
    // defineEnvironment() moves the helper but leaves this mapping pointed at the skeleton.
    expect(array_values(ServiceProvider::pathsToPublish(null, 'sandboxed-config')))
        ->toBe([$this->publishSandbox('config/sandboxed.php')]);
});

it('writes a real publish into the sandbox and nothing into the skeleton', function (): void {
    expect(Artisan::call('vendor:publish', ['--tag' => ['sandboxed-config', 'sandboxed-migrations'], '--force' => true]))
        ->toBe(0)
        ->and($this->publishSandbox('config/sandboxed.php'))->toBeFile()
        ->and(glob($this->publishSandbox('database/migrations/*_create_sandboxed_table.php')))->toHaveCount(1)
        ->and(sharedSkeleton().'/config/sandboxed.php')->not->toBeFile();
});

it('resolves the app namespace before moving app/, so generators still find it', function (): void {
    expect($this->app->getNamespace())->toBe('App\\');
});

it('gives every test its own sandbox', function (): void {
    static $seen = [];

    expect($seen)->not->toContain($this->publishSandbox());

    $seen[] = $this->publishSandbox();
})->with(['first', 'second', 'third']);

it('deletes the sandbox when the test ends, and tolerates running twice', function (): void {
    $root = $this->publishSandbox();
    file_put_contents($root.'/config/left-over.php', '<?php return [];');

    $this->tearDownUsesPublishSandbox();
    $this->tearDownUsesPublishSandbox();

    expect($root)->not->toBeDirectory();
});

it('fails when a tag publishes into the shared skeleton', function (): void {
    expect(fn () => $this->assertPublishesIntoSandbox('sandboxed-config', 'sandboxed-skeleton'))
        ->toThrow(AssertionFailedError::class, 'sandboxed-skeleton');
});

it('fails on a tag nothing publishes, instead of passing over an empty mapping', function (): void {
    expect(fn () => $this->assertPublishesIntoSandbox('not-a-tag'))
        ->toThrow(AssertionFailedError::class, 'not-a-tag');
});

it('fails when a helper path moved back out of the sandbox', function (): void {
    $this->app->useConfigPath(sharedSkeleton().'/config');

    expect(fn () => $this->assertPublishesIntoSandbox())
        ->toThrow(AssertionFailedError::class, 'config');
});

it('refuses to start when the sandbox was never set up', function (): void {
    // What happens when a test case overrides getPackageProviders() without calling the
    // trait's: the providers register against the skeleton. The guard runs in setUp.
    $root = new ReflectionProperty(PublishSandboxTestCase::class, 'publishSandboxRoot');
    $kept = $root->getValue($this);
    $root->setValue($this, '');

    try {
        expect(fn () => $this->setUpUsesPublishSandbox())->toThrow(LogicException::class, 'getPackageProviders')
            ->and(fn () => $this->publishSandbox())->toThrow(LogicException::class);
    } finally {
        $root->setValue($this, $kept);
    }
});
