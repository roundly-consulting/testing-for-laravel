<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\RealEngineTestCase;

uses(RealEngineTestCase::class);

it('applies a clean order through the static escape hatch', function (): void {
    Assert::migrationsApplyOnConnection(fixturePath('green/references-on'), 'sqlite_real');

    // Reaching here without an exception is the assertion.
    expect(true)->toBeTrue();
});

it('fails loudly through the static negative-control escape hatch on sqlite', function (): void {
    expect(fn (): mixed => Assert::brokenOrderIsRejectedOnConnection(
        fixturePath('broken/child-before-parent'),
        fn (array $files): array => $files,
        'sqlite_real',
    ))->toThrow(AssertionFailedError::class);
});

it('applies a named-class migration and loads it twice', function (): void {
    // The Migrator resolves a named class from the file name; a second load in the same
    // process must reuse that class rather than require the file again (a fatal redeclare).
    expect(fixturePath('named-class'))->toApplyOnConnection('sqlite_real', migrations: 1)
        ->and(fixturePath('named-class'))->toApplyOnConnection('sqlite_real', migrations: 1);
});

it('fails a named class that is not a Migration, by name, on every load', function (): void {
    foreach ([1, 2] as $load) {
        expect(fn (): mixed => expect(fixturePath('loader/named-class-not-migration'))->toApplyOnConnection('sqlite_real'))
            ->toThrow(AssertionFailedError::class, 'declares `CreateNotAMigrationTable`, which is not a Migration');
    }
});

it('refuses a non-probe real-engine connection', function (): void {
    // The runner drops every table on its target, before and after. A real-engine connection
    // that is not an isolated probe may be a developer's database — Testbench's stock
    // `mariadb` is `laravel` as passwordless root — or the suite's own schema.
    config()->set('database.connections.pgsql_suite', DriverMatrix::connectionConfig('pgsql'));
    $default = config('database.default');

    foreach (['mariadb', 'pgsql_suite'] as $connection) {
        expect(fn (): mixed => expect(fixturePath('green/references-on'))->toApplyOnConnection($connection))
            ->toThrow(AssertionFailedError::class, "[{$connection}] is neither sqlite nor an isolated probe")
            ->and(fn (): mixed => expect(fixturePath('green/references-on'))
                ->toRejectBrokenOrderOnConnection(fn (array $files): array => $files, $connection))
            ->toThrow(AssertionFailedError::class, "[{$connection}] is neither sqlite nor an isolated probe");
    }

    expect(config('database.default'))->toBe($default);
});

it("refuses the suite's own real-engine connection and leaves its tables standing", function (): void {
    Schema::connection('testing')->create('precious_rows', function (Blueprint $table): void {
        $table->id();
    });

    expect(fn (): mixed => expect(fixturePath('green/references-on'))->toApplyOnConnection('testing'))
        ->toThrow(AssertionFailedError::class, '[testing] is neither sqlite nor an isolated probe')
        ->and(Schema::connection('testing')->hasTable('precious_rows'))->toBeTrue();
})->skip(fn (): bool => DriverMatrix::driver() === 'sqlite', 'the suite runs on sqlite on this leg');
