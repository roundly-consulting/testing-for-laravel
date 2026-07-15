<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Fixtures;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Testing\Fixtures\Concerns\RecordsLocks;

/**
 * A global, static registry of the pessimistic locks a test observed, and the
 * transaction depth each one happened at.
 *
 * SQLite compiles `lockForUpdate()` to an **empty string**, so a lock leaves no trace
 * in the emitted SQL and a test cannot tell a locked read from an unlocked one (and
 * emitting a real `FOR UPDATE` is a SQLite syntax error). This registry is the shared
 * sink both observable variants write to:
 *
 *  - **Variant A** ({@see LockRecordingBuilder} via
 *    the {@see RecordsLocks} model trait)
 *    records the lock at the moment the builder method is called.
 *  - **Variant B** ({@see LockRecordingGrammar}) compiles the lock to a trailing
 *    `/* lock-for-update *\/` SQL comment; {@see self::listenForMarkers()} wires a
 *    `DB::listen()` that records every marked query and the depth it ran at.
 *
 * The **transaction depth** is the load-bearing datum: the shops retrofit rejected the
 * deleted `LockedUpdate` helper precisely because its lock landed in a savepoint
 * released before the ledger write — a fact only observable as `transactionDepth`.
 */
final class LockRecorder
{
    /** @var list<array{marker: string, sql: string, transactionDepth: int}> */
    private static array $records = [];

    /**
     * Record one observed lock.
     */
    public static function record(string $marker, int $transactionDepth, string $sql = ''): void
    {
        self::$records[] = [
            'marker' => $marker,
            'sql' => $sql,
            'transactionDepth' => $transactionDepth,
        ];
    }

    /**
     * The locks observed since the last {@see self::flush()}, in order.
     *
     * @return list<array{marker: string, sql: string, transactionDepth: int}>
     */
    public static function recorded(): array
    {
        return self::$records;
    }

    /**
     * Clear the registry — call in `setUp()` and before driving a race.
     */
    public static function flush(): void
    {
        self::$records = [];
    }

    /**
     * Variant B wiring: listen for the trailing lock markers {@see LockRecordingGrammar}
     * emits and record each with the transaction depth it executed at. Call once, after
     * the recording grammar is installed on the connection.
     */
    public static function listenForMarkers(): void
    {
        DB::listen(static function (QueryExecuted $query): void {
            if (preg_match('#/\* (lock-[a-z-]+) \*/#', $query->sql, $matches) === 1) {
                self::record($matches[1], $query->connection->transactionLevel(), $query->sql);
            }
        });
    }
}
