<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Database;

use Illuminate\Database\Connection;

/**
 * The shape of a database, as one hash: what {@see KeptSchema} compares after every test to
 * tell "the test only wrote rows" from "the test changed the schema".
 *
 * Covered, per engine (all scoped to the connection's own schema or database):
 *
 *  - **every engine** — each table and its columns (position, type, nullability, default),
 *    indexes, primary/unique/foreign/check constraints, views, triggers, and every row of the
 *    migrations table;
 *  - **PostgreSQL** — also every relation's kind, identity/generated columns, the view
 *    definitions and every sequence's type, bounds, start and step (not its current value —
 *    that is a key counter, which the reset rewinds);
 *  - **MySQL** — also each table's engine and collation and each column's collation and extra
 *    (`auto_increment`, `on update`);
 *  - **SQLite** — the DDL of every object in `sqlite_master` and `sqlite_temp_master` (a
 *    `create temp table` included), the `foreign_keys` switch and the attached databases.
 *
 * Not covered: Postgres types, domains, functions, extensions and other schemas; MySQL
 * routines and events; grants; and on in-memory SQLite the rest of the shared connection's
 * session state — other pragmas (`recursive_triggers`, `case_sensitive_like`, …) and functions
 * registered on the PDO. A test that changes any of those must undo it, exactly as it must
 * today: the drop-per-test reset does not remove them either.
 *
 * @internal Part of {@see KeptSchema}.
 */
final class SchemaCatalogue
{
    public static function of(Connection $connection, string $migrationsTable): string
    {
        $rows = match ($connection->getDriverName()) {
            'pgsql' => $connection->select(self::POSTGRES),
            'sqlite' => $connection->select(self::SQLITE),
            default => $connection->select(self::MYSQL),
        };

        $entries = array_map(static fn (object $row): string => (string) KeptSchema::field($row, 'entry'), $rows);

        foreach ($connection->table($migrationsTable)->orderBy('id')->get(['id', 'migration', 'batch']) as $migration) {
            $entries[] = 'm|'.$migration->id.'|'.$migration->migration.'|'.$migration->batch;
        }

        sort($entries);

        return hash('xxh128', implode("\n", $entries));
    }

    private const string POSTGRES = <<<'SQL'
        select 'r|' || c.relname || '|' || c.relkind::text as entry
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = current_schema() and c.relkind not in ('i', 'I', 't')
        union all
        select 'c|' || c.relname || '|' || a.attnum || '|' || a.attname || '|' || format_type(a.atttypid, a.atttypmod)
            || '|' || a.attnotnull || '|' || a.attidentity::text || '|' || a.attgenerated::text
            || '|' || coalesce(pg_get_expr(d.adbin, d.adrelid), '~')
        from pg_attribute a
        join pg_class c on c.oid = a.attrelid
        join pg_namespace n on n.oid = c.relnamespace
        left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
        where n.nspname = current_schema() and c.relkind in ('r', 'p') and a.attnum > 0 and not a.attisdropped
        union all
        select 'i|' || pg_get_indexdef(i.indexrelid)
        from pg_index i
        join pg_class c on c.oid = i.indrelid
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = current_schema()
        union all
        select 'k|' || con.conrelid::regclass::text || '|' || con.conname || '|' || pg_get_constraintdef(con.oid)
        from pg_constraint con
        join pg_namespace n on n.oid = con.connamespace
        where n.nspname = current_schema()
        union all
        select 'v|' || c.relname || '|' || pg_get_viewdef(c.oid)
        from pg_class c
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = current_schema() and c.relkind in ('v', 'm')
        union all
        select 't|' || c.relname || '|' || pg_get_triggerdef(t.oid)
        from pg_trigger t
        join pg_class c on c.oid = t.tgrelid
        join pg_namespace n on n.oid = c.relnamespace
        where n.nspname = current_schema() and not t.tgisinternal
        union all
        select 's|' || sequencename || '|' || data_type::text || '|' || start_value || '|' || min_value
            || '|' || max_value || '|' || increment_by || '|' || cycle
        from pg_sequences
        where schemaname = current_schema()
        SQL;

    private const string MYSQL = <<<'SQL'
        select concat_ws('|', 'T', table_name, table_type, coalesce(engine, '~'), coalesce(table_collation, '~')) as entry
        from information_schema.tables where table_schema = database()
        union all
        select concat_ws('|', 'c', table_name, ordinal_position, column_name, column_type, is_nullable,
            coalesce(column_default, '~'), extra, coalesce(collation_name, '~'))
        from information_schema.columns where table_schema = database()
        union all
        select concat_ws('|', 'i', table_name, index_name, seq_in_index, coalesce(column_name, '~'), non_unique,
            coalesce(sub_part, '~'))
        from information_schema.statistics where table_schema = database()
        union all
        select concat_ws('|', 'k', table_name, constraint_name, column_name,
            coalesce(referenced_table_name, '~'), coalesce(referenced_column_name, '~'))
        from information_schema.key_column_usage where table_schema = database()
        union all
        select concat_ws('|', 'n', table_name, constraint_name, constraint_type)
        from information_schema.table_constraints where table_schema = database()
        union all
        select concat_ws('|', 'v', table_name, view_definition)
        from information_schema.views where table_schema = database()
        union all
        select concat_ws('|', 't', event_object_table, trigger_name, action_timing, event_manipulation, action_statement)
        from information_schema.triggers where trigger_schema = database()
        SQL;

    // `temp` is left out of the database list: whether SQLite has opened its temp schema yet is
    // not a schema change, and every temp object is listed from sqlite_temp_master anyway.
    private const string SQLITE = <<<'SQL'
        select type || '|' || name || '|' || tbl_name || '|' || coalesce(sql, '~') as entry from sqlite_master
        union all
        select 'temp|' || type || '|' || name || '|' || tbl_name || '|' || coalesce(sql, '~') from sqlite_temp_master
        union all
        select 'f|' || foreign_keys from pragma_foreign_keys
        union all
        select 'd|' || name || '|' || coalesce(file, '~') from pragma_database_list where name <> 'temp'
        SQL;
}
