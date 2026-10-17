<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Database;

/**
 * One key counter as a fresh migrate left it: a MySQL table's `AUTO_INCREMENT`, a Postgres
 * sequence, or a SQLite `sqlite_sequence` row.
 *
 * @internal Part of {@see KeptSchema}.
 */
final readonly class KeyCounter
{
    /**
     * @param  string  $name  the table (MySQL, SQLite) or the quoted, schema-qualified sequence (Postgres)
     * @param  int  $value  the counter's value
     * @param  bool  $called  Postgres only: whether `$value` has been handed out already
     */
    public function __construct(
        public string $name,
        public int $value,
        public bool $called = true,
    ) {}
}
