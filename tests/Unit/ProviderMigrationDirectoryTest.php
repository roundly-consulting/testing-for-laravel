<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Support\ProviderMigrationDirectory;
use RoundlyConsulting\Testing\Tests\Fixtures\FakePackage\FakePackageServiceProvider;

it('locates a provider migrations directory by reflection', function (): void {
    $directory = ProviderMigrationDirectory::locate(FakePackageServiceProvider::class);

    expect(is_dir($directory))->toBeTrue()
        ->and(basename($directory))->toBe('migrations');
});

it('fails when the provider class does not exist', function (): void {
    expect(fn (): string => ProviderMigrationDirectory::locate('Totally\\Missing\\ProviderXyz'))
        ->toThrow(AssertionFailedError::class);
});

it('fails when a class has no file on disk', function (): void {
    // An internal PHP class has no source file, so reflection cannot locate it.
    expect(fn (): string => ProviderMigrationDirectory::locate(stdClass::class))
        ->toThrow(AssertionFailedError::class);
});

it('fails when a class has no database/migrations directory above it', function (): void {
    expect(fn (): string => ProviderMigrationDirectory::locate(ProviderMigrationDirectory::class))
        ->toThrow(AssertionFailedError::class);
});

it("does not climb past the provider's package root", function (): void {
    // A host app with its own database/migrations, and an installed package whose provider sits
    // in vendor/acme/pkg/src — the package has a composer.json but no migrations. The walk must
    // stop at that package root, never land on the HOST's migrations.
    $app = sys_get_temp_dir().'/provider-root-'.bin2hex(random_bytes(6));
    $package = $app.'/vendor/acme/pkg';

    mkdir($app.'/database/migrations', 0777, true);
    mkdir($package.'/src/Providers', 0777, true);
    file_put_contents($package.'/composer.json', '{"name": "acme/pkg"}');
    file_put_contents($package.'/src/Providers/AcmeClimbServiceProvider.php', <<<'PHP'
        <?php

        namespace Acme\Climb;

        final class AcmeClimbServiceProvider extends \Illuminate\Support\ServiceProvider {}
        PHP);

    try {
        require_once $package.'/src/Providers/AcmeClimbServiceProvider.php';

        expect(fn (): string => ProviderMigrationDirectory::locate('Acme\\Climb\\AcmeClimbServiceProvider'))
            ->toThrow(AssertionFailedError::class, 'Could not locate a database/migrations directory');

        // The package's own directory, two levels up from a nested provider, is still found.
        mkdir($package.'/database/migrations', 0777, true);

        expect(ProviderMigrationDirectory::locate('Acme\\Climb\\AcmeClimbServiceProvider'))
            ->toBe(realpath($package).'/database/migrations');
    } finally {
        (new Filesystem)->deleteDirectory($app);
    }
});
