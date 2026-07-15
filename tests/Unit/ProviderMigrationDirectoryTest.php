<?php

declare(strict_types=1);

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
