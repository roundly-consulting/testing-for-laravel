<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Database\QueryException;

/**
 * Whether an engine's refusal of a migration set is an **ordering** refusal — the only
 * kind that proves the order matters.
 *
 * A broken order fails in a small, recognisable number of ways: a table (or column) that a
 * later migration creates is not there yet, or a foreign key cannot be created onto it. The
 * negative control used to count *any* `QueryException` as the engine rejecting the order,
 * which is how it passed on a Postgres that was down — "connection refused" is a
 * `QueryException` too — and on a set that simply held invalid SQL. Neither says anything
 * about order; both fail identically in every order.
 *
 * Recognised, by engine:
 *  - **pgsql** — SQLSTATE `42P01` undefined table, `42703` undefined column, `42830` invalid
 *    foreign key, `23503` foreign-key violation.
 *  - **mysql/mariadb** — SQLSTATE `42S02` / `42S22` (table / column not found), and the
 *    driver codes for a foreign key it could not create: 1005, 1215, 1216, 1452, 1822, 1824,
 *    3734, 6125 (reported under the catch-all `HY000`, hence the codes).
 *  - **sqlite** — `no such table`, `no such column`, `FOREIGN KEY constraint failed`,
 *    `foreign key mismatch` (sqlite reports nearly everything as `HY000`, so the message is
 *    the only signal).
 *
 * @internal
 */
final class OrderingRejection
{
    /** @var list<string> */
    private const array SQLSTATES = ['42P01', '42703', '42830', '23503', '42S02', '42S22'];

    /** @var list<int> */
    private const array MYSQL_CODES = [1005, 1146, 1054, 1215, 1216, 1452, 1822, 1824, 3734, 6125];

    /** @var list<string> */
    private const array SQLITE_MESSAGES = [
        'no such table',
        'no such column',
        'foreign key constraint failed',
        'foreign key mismatch',
    ];

    public static function recognises(QueryException $exception): bool
    {
        $info = $exception->errorInfo;
        $state = (string) (is_array($info) ? ($info[0] ?? '') : '');
        $code = is_array($info) && is_numeric($info[1] ?? null) ? (int) $info[1] : null;

        if ($state === '') {
            $state = (string) $exception->getCode();
        }

        if (in_array($state, self::SQLSTATES, true)) {
            return true;
        }

        // SQLite and Postgres driver codes are small (1, 7, 19, 26…) and never collide with
        // these four-digit MySQL error numbers.
        if ($code !== null && in_array($code, self::MYSQL_CODES, true)) {
            return true;
        }

        $message = strtolower($exception->getMessage());

        foreach (self::SQLITE_MESSAGES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
