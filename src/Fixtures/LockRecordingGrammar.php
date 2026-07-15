<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Fixtures;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * Variant B of the lock recorder: a SQLite query grammar that compiles a pessimistic
 * lock to a **trailing SQL comment** instead of dropping it.
 *
 * Stock {@see SQLiteGrammar} compiles `lockForUpdate()` to an empty string — the lock
 * vanishes and nothing downstream can see it. This grammar compiles it to
 * `/* lock-for-update *\/` (or `/* lock-shared *\/`), a comment SQLite runs without
 * complaint, so the statement executes exactly as before **and** carries a marker that
 * `DB::listen()` can pick up — telling a test which query took the lock, and (via the
 * connection's `transactionLevel()`) at what transaction depth.
 *
 * Use this variant when the model is not subclassable in the suite or the lock is
 * buried inside a package action. Install it and start recording with:
 *
 * ```php
 * $connection->setQueryGrammar(new LockRecordingGrammar($connection));
 * LockRecorder::flush();
 * LockRecorder::listenForMarkers();
 * // … drive the flow that locks …
 * expect(LockRecorder::recorded())->toHaveCount(1);
 * ```
 */
class LockRecordingGrammar extends SQLiteGrammar
{
    /**
     * @param  bool|string  $value
     */
    protected function compileLock(Builder $query, $value): string
    {
        $marker = match (true) {
            $value === true => 'lock-for-update',
            $value === false => 'lock-shared',
            default => 'lock-custom',
        };

        return '/* '.$marker.' */';
    }
}
