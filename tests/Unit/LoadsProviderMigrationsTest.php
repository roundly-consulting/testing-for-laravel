<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Tests\Fixtures\FakePackage\FakePackageServiceProvider;
use RoundlyConsulting\Testing\Tests\Support\ExposedMigrationLoader;

it('resolves a provider class to its migrations directory by reflection', function (): void {
    $directory = ExposedMigrationLoader::directoryFor(FakePackageServiceProvider::class);

    expect(is_dir($directory))->toBeTrue()
        ->and(basename($directory))->toBe('migrations');
});

it('returns a literal directory unchanged', function (): void {
    $directory = fixturePath('green/bare-constrained');

    expect(ExposedMigrationLoader::directoryFor($directory))->toBe($directory);
});

it('fails a source that is neither a provider class nor a directory', function (): void {
    expect(fn (): string => ExposedMigrationLoader::directoryFor('/no/such/path'))
        ->toThrow(AssertionFailedError::class);
});

it('fails a class with no database/migrations directory', function (): void {
    expect(fn (): string => ExposedMigrationLoader::directoryFor(ExposedMigrationLoader::class))
        ->toThrow(AssertionFailedError::class);
});
