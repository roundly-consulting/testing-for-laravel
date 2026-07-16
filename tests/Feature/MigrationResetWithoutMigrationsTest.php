<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\FileDatabaseWithoutMigrationsTestCase;

uses(FileDatabaseWithoutMigrationsTestCase::class);

/**
 * A real-engine suite that loaded no migrations: the reset must do nothing.
 *
 * The drop is scoped to what this package put there — schema loaded through
 * `migrationSources()`. A suite that loaded nothing (or one using `RefreshDatabase`, which
 * caches no migrator and owns its own reset) must be left alone rather than have its database
 * emptied by a base class it merely extends.
 */
it('leaves a suite that loaded no migrations alone', function (): void {
    // Nothing was migrated, so nothing exists to reset.
    expect(Schema::hasTable('fake_widgets'))->toBeFalse();

    // A table this suite makes for itself is its own business, and the teardown drop must not
    // be what deletes it — proving the branch returns before the drop.
    Schema::create('hand_rolled', function ($table): void {
        $table->id();
    });

    expect(Schema::hasTable('hand_rolled'))->toBeTrue()
        ->and(DB::connection()->transactionLevel())->toBe(0);
});
