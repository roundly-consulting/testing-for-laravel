<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\RefreshDatabaseTestCase;

uses(RefreshDatabaseTestCase::class);

/**
 * The counter-weight to {@see MigrationResetWithoutMigrationsTest}: making the reset
 * unconditional on *migrations* must not make it unconditional on *everything*.
 *
 * `RefreshDatabase` owns a competing reset. If this suite's tables were dropped, the schema it
 * migrated once would be gone for every later test; if its connection were purged, the
 * transaction it is about to roll back would be cut out from under it. Owning a competing reset
 * — not "having no migrations" — is the real reason to stand down.
 */
it('leaves a RefreshDatabase suite entirely alone', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue();

    $connectionsBefore = array_keys(DB::getConnections());

    $this->tearDownInteractsWithMigrations();

    // The migrated schema survives — it is RefreshDatabase's, not ours to drop.
    expect(Schema::hasTable('fake_widgets'))->toBeTrue()
        // And the connection is still open, still carrying the transaction to roll back.
        ->and(array_keys(DB::getConnections()))->toBe($connectionsBefore);
});
