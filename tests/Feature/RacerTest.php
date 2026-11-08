<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Concurrency\Racer;
use RoundlyConsulting\Testing\Database\DriverMatrix;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;

uses(MinimalPackageTestCase::class);

/**
 * Forking needs pcntl + posix. The racer itself runs on any driver; only a real engine makes
 * a race meaningful, which is what {@see Racer::available()} answers.
 */
function canFork(): bool
{
    return function_exists('pcntl_fork') && function_exists('posix_kill');
}

// ---------------------------------------------------------------------------
// The fork, the outcome and the exit — on every leg.
// ---------------------------------------------------------------------------

it('reports the outcome the operation returns', function (): void {
    $racer = (new Racer)->run(fn (): string => 'won');

    expect($racer->outcome())->toBe('won');
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('runs the operation on its own connection, never the parent\'s', function (): void {
    DB::selectOne('select 1 as one');

    $racer = (new Racer)->run(fn (): string => DB::getDefaultConnection().':'.DB::connection()->getDriverName());

    expect($racer->outcome())->toBe(Racer::CONNECTION.':'.DriverMatrix::driver())
        ->and(DB::getDefaultConnection())->toBe('testing')
        // The parent's session survived the child: SIGKILL runs no destructor that closes it.
        ->and(DB::selectOne('select 1 as one')->one)->toEqual(1);
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('reports a throwable as an error outcome', function (): void {
    $racer = (new Racer)->run(fn (): string => throw new RuntimeException('lost the race'));

    expect($racer->outcome())->toBe('error: RuntimeException: lost the race');
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('reports an operation that exits instead of returning', function (): void {
    // Open the parent's session first, so the child inherits it.
    DB::selectOne('select 1 as one');

    $racer = (new Racer)->run(function (): string {
        exit(0);
    });

    expect($racer->outcome())->toBe('error: the racer exited without reporting an outcome')
        // A normal exit would have run the destructors that close the sessions the child shares
        // with the parent; on a real engine the next parent query would then fail.
        ->and(DB::selectOne('select 1 as one')->one)->toEqual(1);
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('keeps an empty outcome apart from a racer that never reported', function (): void {
    $empty = (new Racer)->run(fn (): string => '');
    $killed = (new Racer)->run(function (): string {
        posix_kill(posix_getpid(), SIGKILL);

        return 'never written';
    });

    expect($empty->outcome())->toBe('')
        ->and($killed->outcome())->toBe('error: the racer died without reporting an outcome');
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('returns from the wait once a racer that never blocks has finished', function (): void {
    $racer = (new Racer)->run(fn (): string => 'done')->waitUntilBlockedOrDone();

    expect($racer->outcome())->toBe('done');
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('kills a racer that neither blocks nor finishes in time', function (): void {
    $racer = (new Racer)->run(function (): string {
        sleep(30);

        return 'too late';
    });

    $started = microtime(true);

    expect(fn () => $racer->waitUntilBlockedOrDone(timeoutSeconds: 0.3))
        ->toThrow(RuntimeException::class, 'neither blocked nor finished');

    expect($racer->outcome())->toStartWith('error: the racer was killed')
        ->and(microtime(true) - $started)->toBeLessThan(5.0);
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('is single-use', function (): void {
    $racer = (new Racer)->run(fn (): string => 'once');

    expect(fn () => $racer->run(fn (): string => 'twice'))->toThrow(LogicException::class);

    $racer->outcome();
})->skip(fn (): bool => ! canFork(), 'needs pcntl and posix');

it('refuses an outcome or a wait before it ran', function (): void {
    expect(fn () => (new Racer)->outcome())->toThrow(LogicException::class)
        ->and(fn () => (new Racer)->waitUntilBlockedOrDone())->toThrow(LogicException::class);
});

it('is available only on a real engine that can fork', function (): void {
    expect(Racer::available())->toBe(
        in_array(DriverMatrix::driver(), ['pgsql', 'mysql'], true) && canFork(),
    );
});

it('is not available on sqlite, where a race shares nothing', function (): void {
    expect(Racer::available())->toBeFalse();
})->skip(fn (): bool => DriverMatrix::driver() !== 'sqlite', 'runs on the sqlite leg');

// ---------------------------------------------------------------------------
// A real race — on the real-engine legs.
// ---------------------------------------------------------------------------

it('waits until the racer blocks on a row the parent holds, then lets it through', function (): void {
    Schema::create('race_rows', function (Blueprint $table): void {
        $table->id();
        $table->integer('value');
    });
    DB::table('race_rows')->insert(['id' => 1, 'value' => 1]);

    DB::beginTransaction();
    DB::table('race_rows')->where('id', 1)->lockForUpdate()->first();

    $racer = (new Racer)->run(function (): string {
        DB::table('race_rows')->where('id', 1)->update(['value' => 2]);

        return 'updated';
    })->waitUntilBlockedOrDone();

    // Blocked, not finished: the row is still the parent's.
    expect(DB::table('race_rows')->value('value'))->toBe(1);

    DB::commit();

    expect($racer->outcome())->toBe('updated')
        ->and(DB::table('race_rows')->value('value'))->toBe(2);
})->skip(fn (): bool => ! Racer::available(), 'needs a real engine (pgsql/mysql) and pcntl + posix');
