<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Database\KeptSchema;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\MigrationRuns;
use RoundlyConsulting\Testing\Tests\Support\KeptSchemaTestCase;

uses(KeptSchemaTestCase::class);

/**
 * The worker's side of a kept schema: forked children, the drop when the worker exits, a second
 * migrate inside one test, and the signature extras.
 */

/**
 * Run `$child` in a forked process that ends the way a racer must: with SIGKILL, so no shutdown
 * handler and no destructor runs there.
 *
 * @param  Closure(): void  $child
 */
function inForkedChild(Closure $child): void
{
    $pid = pcntl_fork();

    if ($pid === 0) {
        try {
            $child();
        } finally {
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    pcntl_waitpid($pid, $status);

    expect(pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL)->toBeTrue();
}

function forkingIsUnavailable(): bool
{
    return ! function_exists('pcntl_fork') || ! function_exists('posix_kill');
}

it('keeps the schema intact across a forked child that ends with SIGKILL', function (): void {
    $kept = KeptSchema::current();

    inForkedChild(static function (): void {
        // A racer opens a session of its own and writes through it. In-memory SQLite has no
        // second session to open: the child writes to its own copy of the database.
        config()->set('database.connections.racer', DriverMatrix::connectionConfig());
        $connection = DriverMatrix::driver() === 'sqlite' ? DB::connection() : DB::connection('racer');
        $connection->table('kept_owners')->insert(['name' => 'from the child']);

        // …and inherits the worker's shutdown handler, which must leave the parent's schema alone.
        KeptSchema::shutdown();
    });

    $expected = DriverMatrix::driver() === 'sqlite' ? 0 : 1;

    expect(DB::table('kept_owners')->count())->toBe($expected)
        ->and(Schema::hasTable('kept_items'))->toBeTrue()
        ->and(KeptSchema::current())->toBe($kept)
        ->and($kept?->reset())->toBeTrue()
        ->and(DB::table('kept_owners')->count())->toBe(0);
})->skip(fn (): bool => forkingIsUnavailable(), 'needs pcntl and posix');

it('never hands out a schema whose session a child closed', function (): void {
    $kept = KeptSchema::current();
    $runs = MigrationRuns::of('kept');

    // What a child that exits normally does: its destructors close every session it inherited —
    // on a real engine the parent's own server sessions, the kept schema's included.
    inForkedChild(static fn () => (fn (): Connection => $this->session)->call($kept)->disconnect());

    if (DriverMatrix::driver() === 'sqlite') {
        // The child closed its own copy of the in-memory database.
        expect($kept?->reset())->toBeTrue();

        return;
    }

    // Refused, never half-done; the discard survives the dead session, and the next migrate
    // drops the leftovers on its own connection.
    expect($kept?->reset())->toBeFalse();

    KeptSchema::discard();
    $this->defineDatabaseMigrations();

    expect(MigrationRuns::of('kept'))->toBe($runs + 1)
        ->and(KeptSchema::current())->not->toBeNull()
        ->and(KeptSchema::current()?->reset())->toBeTrue();
})->skip(fn (): bool => forkingIsUnavailable(), 'needs pcntl and posix');

it('drops the kept tables when the worker exits', function (): void {
    KeptSchema::shutdown();

    expect(KeptSchema::current())->toBeNull();

    if (DriverMatrix::driver() !== 'sqlite') {
        // As empty as the drop-per-test reset always left the worker database.
        expect(DB::connection()->getSchemaBuilder()->getTables(DB::connection()->getSchemaBuilder()->getCurrentSchemaName()))->toBe([]);
    }
});

it('migrates from scratch when a test migrates a second time', function (): void {
    $runs = MigrationRuns::of('kept');

    DB::table('kept_owners')->insert(['name' => 'before']);

    $this->defineDatabaseMigrations();

    expect(MigrationRuns::of('kept'))->toBe($runs + 1)
        ->and(DB::table('kept_owners')->count())->toBe(0);
});

it('keeps another schema for a test whose signature extras differ', function (): void {
    $runs = MigrationRuns::of('kept');
    $kept = KeptSchema::current();

    $this->tearDownInteractsWithMigrations();
    KeptSchemaTestCase::$flavour = 'other';

    try {
        $this->defineDatabaseMigrations();
    } finally {
        KeptSchemaTestCase::$flavour = null;
    }

    expect(MigrationRuns::of('kept'))->toBe($runs + 1)
        ->and(KeptSchema::current())->not->toBeNull()
        ->and(KeptSchema::current()?->signature)->not->toBe($kept?->signature);
});
