<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Database;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use RoundlyConsulting\Testing\PackageTestCase;
use Throwable;

/**
 * A schema one worker migrated once and keeps between tests: what it was built from (the
 * signature), what it looks like (the {@see SchemaCatalogue catalogue}), where its key counters
 * stood, and the session that empties it again. The engine behind
 * {@see PackageTestCase::keepsMigratedSchema()}.
 *
 * The session is the worker's own, outside the test's app. On a real engine it is a second
 * connection to the worker database; on in-memory SQLite it is the PDO that *is* the database,
 * handed to each test's connection.
 *
 * A worker keeps at most one schema. A test with another signature — or one that does not keep
 * its schema, on a real engine — discards it first, so a fresh migrate never meets kept tables.
 *
 * @internal Driven by {@see PackageTestCase}; not a public API.
 */
final class KeptSchema
{
    private static ?self $kept = null;

    /** @var array<string, true> signatures whose migrations write rows: never kept */
    private static array $seeding = [];

    private static ?int $owner = null;

    /**
     * @param  list<string>  $tables  every table but the migrations table
     * @param  list<KeyCounter>  $counters
     */
    private function __construct(
        public readonly string $signature,
        private readonly Connection $session,
        private readonly string $migrationsTable,
        private readonly array $tables,
        private readonly string $catalogue,
        private readonly array $counters,
    ) {}

    /**
     * Whether the connection's engine can keep a schema: MySQL, MariaDB, PostgreSQL, and SQLite
     * on a private `:memory:` database. A file or shared-cache SQLite database, another engine,
     * or a table prefix keeps today's drop-per-test reset.
     */
    public static function supports(Connection $connection): bool
    {
        if ($connection->getTablePrefix() !== '') {
            return false;
        }

        return match ($connection->getDriverName()) {
            'mysql', 'mariadb', 'pgsql' => true,
            'sqlite' => $connection->getConfig('database') === ':memory:',
            default => false,
        };
    }

    /**
     * The schema this worker keeps, if any.
     */
    public static function current(): ?self
    {
        return self::$kept;
    }

    /**
     * Hand the test the kept schema for its signature, or migrate and keep a new one.
     *
     * Null — nothing kept — when the migrations wrote rows: wiping them between tests would hand
     * every later test an emptier database than a fresh migrate does, so that signature keeps the
     * drop-per-test reset for the rest of the run.
     *
     * @param  Closure(): void  $migrate  runs the test's migrations on `$connection`
     */
    public static function keep(Connection $connection, string $signature, string $migrationsTable, Closure $migrate): ?self
    {
        if (self::$kept?->signature === $signature) {
            self::$kept->attach($connection);

            return self::$kept;
        }

        self::discard();

        if ($connection->getDriverName() !== 'sqlite') {
            // A previous run's leftovers — a worker killed before its shutdown drop, a discard
            // that lost its session — never meet a fresh migrate. A new in-memory database is
            // empty by definition.
            $connection->getSchemaBuilder()->dropAllTables();
        }

        $migrate();

        if (isset(self::$seeding[$signature])) {
            return null;
        }

        $schema = self::capture($connection, $signature, $migrationsTable);

        if ($schema === null) {
            self::$seeding[$signature] = true;

            return null;
        }

        self::dropOnShutdown();

        return self::$kept = $schema;
    }

    /**
     * A test that does not keep its schema is about to migrate. On a real engine that would
     * meet the kept tables in the same worker database, so they go first. In-memory SQLite
     * needs nothing: the test opens a database of its own.
     */
    public static function release(): void
    {
        if (self::$kept !== null && ! self::$kept->session instanceof SQLiteConnection) {
            self::discard();
        }
    }

    /**
     * Record the schema the migrations just built on `$connection`: null when they wrote rows.
     */
    public static function capture(Connection $connection, string $signature, string $migrationsTable): ?self
    {
        $schema = $connection->getSchemaBuilder();
        $tables = [];

        // The connection's own schema only: on MySQL a bare listing spans every database on the
        // server — the other workers' and the probes' included.
        foreach ($schema->getTables($schema->getCurrentSchemaName()) as $table) {
            $name = (string) $table['name'];

            if ($name !== $migrationsTable && ! str_starts_with($name, 'sqlite_')) {
                $tables[] = $name;
            }
        }

        sort($tables);

        if (self::tablesWithRows($connection, $tables) !== []) {
            return null;
        }

        $session = self::sessionFor($connection);

        return new self(
            $signature,
            $session,
            $migrationsTable,
            $tables,
            SchemaCatalogue::of($session, $migrationsTable),
            self::counters($session),
        );
    }

    /**
     * In-memory SQLite: point the test's connection at the kept database. A real engine needs
     * nothing — the test's connection opens the same worker database by name.
     */
    public function attach(Connection $connection): void
    {
        if ($this->session instanceof SQLiteConnection) {
            $pdo = $this->session->getPdo();
            $connection->setPdo($pdo)->setReadPdo($pdo);
        }
    }

    /**
     * Empty every table and put every key counter back where the migrate left it, so the next
     * test starts exactly where a fresh migrate would leave it. False — nothing reset — when the
     * test changed the schema itself (a dropped table, an extra column, a migrations row, foreign
     * keys switched off) or the session is gone: the caller then {@see self::discard()}s it and
     * the next test migrates from scratch.
     */
    public function reset(): bool
    {
        // SQLite shares the PDO with the test: a transaction the test left open is still open.
        if ($this->session instanceof SQLiteConnection && $this->session->getPdo()->inTransaction()) {
            $this->session->getPdo()->rollBack();
        }

        try {
            if (SchemaCatalogue::of($this->session, $this->migrationsTable) !== $this->catalogue) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        $dirty = self::tablesWithRows($this->session, $this->tables);

        match ($this->session->getDriverName()) {
            'pgsql' => $this->resetPostgres($dirty),
            'sqlite' => $this->resetSqlite($dirty),
            default => $this->resetMysql($dirty),
        };

        return true;
    }

    /**
     * After a test: reset the schema, or throw it away when it cannot be reset. A reset that
     * fails half-way is never handed to the next test — it migrates from scratch — and the
     * failure is rethrown.
     */
    public function settle(): void
    {
        if (self::$kept !== $this) {
            return;
        }

        try {
            if (! $this->reset()) {
                self::discard();
            }
        } catch (Throwable $exception) {
            self::discard();

            throw $exception;
        }
    }

    /**
     * Throw the kept schema away: a real engine's tables are dropped, an in-memory database is
     * let go of. The next test that keeps its schema migrates into an empty database.
     */
    public static function discard(): void
    {
        $kept = self::$kept;
        self::$kept = null;

        if ($kept === null) {
            return;
        }

        try {
            if (! $kept->session instanceof SQLiteConnection) {
                $kept->session->getSchemaBuilder()->dropAllTables();
            }
        } catch (Throwable) {
            // The session is gone (a forked child closed it, the server went away): the next
            // fresh migrate drops the leftovers on its own connection first.
        } finally {
            $kept->session->disconnect();
        }
    }

    /**
     * The worker is done: drop its kept schema, so the next run — or another package's suite on
     * the same local database — starts from the empty database the drop-per-test reset always
     * left behind. A forked child inherits the handler and does nothing: the schema is its
     * parent's.
     */
    public static function shutdown(): void
    {
        if (getmypid() !== self::$owner) {
            return;
        }

        self::discard();
    }

    private static function dropOnShutdown(): void
    {
        if (self::$owner !== null) {
            return;
        }

        self::$owner = (int) getmypid();

        register_shutdown_function(self::shutdown(...));
    }

    private static function sessionFor(Connection $connection): Connection
    {
        if ($connection instanceof SQLiteConnection) {
            // The PDO the migrations just ran on is the database.
            return new SQLiteConnection($connection->getPdo(), ':memory:', '', $connection->getConfig());
        }

        $session = (new ConnectionFactory(new Container))->make($connection->getConfig(), 'kept_schema');

        // MySQL caches the information_schema counters unless asked not to.
        if ($session instanceof MySqlConnection && ! $session->isMaria()) {
            $session->statement('set session information_schema_stats_expiry = 0');
        }

        return $session;
    }

    /**
     * Every key counter as it stands now.
     *
     * MySQL reports a NULL `AUTO_INCREMENT` for a table no session has opened since the server
     * started or the table was created, so both callers read the counters right after
     * {@see self::tablesWithRows()} opened every table.
     *
     * @return list<KeyCounter>
     */
    private static function counters(Connection $session): array
    {
        return match ($session->getDriverName()) {
            'pgsql' => self::postgresCounters($session),
            'sqlite' => self::sqliteCounters($session),
            default => array_values(array_map(
                static fn (object $row): KeyCounter => new KeyCounter((string) self::field($row, 'name'), (int) self::field($row, 'value')),
                $session->select(
                    'select table_name as name, auto_increment as value from information_schema.tables '
                    ."where table_schema = database() and table_type = 'BASE TABLE' and auto_increment is not null",
                ),
            )),
        };
    }

    /**
     * Each sequence's own state: `last_value` alone cannot tell a sequence a migration restarted
     * at 100 (`startingValue(100)`) from one that has handed 100 out.
     *
     * @return list<KeyCounter>
     */
    private static function postgresCounters(Connection $session): array
    {
        $sequences = array_map(
            static fn (object $row): string => (string) self::field($row, 'name'),
            $session->select("select format('%I.%I', schemaname, sequencename) as name from pg_sequences where schemaname = current_schema()"),
        );

        if ($sequences === []) {
            return [];
        }

        $grammar = $session->getQueryGrammar();

        // The names come from the catalogue, already quoted by format('%I'), never from a test.
        $rows = $session->select(implode(' union all ', array_map(
            static fn (string $sequence): string => 'select '.$grammar->quoteString($sequence).' as name, last_value, is_called from '.$sequence,
            $sequences,
        )));

        return array_values(array_map(
            static fn (object $row): KeyCounter => new KeyCounter(
                (string) self::field($row, 'name'),
                (int) self::field($row, 'last_value'),
                (bool) self::field($row, 'is_called'),
            ),
            $rows,
        ));
    }

    /**
     * @return list<KeyCounter>
     */
    private static function sqliteCounters(Connection $session): array
    {
        // AUTOINCREMENT counters live in sqlite_sequence, created with the first such table.
        if ((int) $session->scalar("select count(*) from sqlite_master where name = 'sqlite_sequence'") === 0) {
            return [];
        }

        return array_values(array_map(
            static fn (object $row): KeyCounter => new KeyCounter((string) self::field($row, 'name'), (int) self::field($row, 'seq')),
            $session->select('select name, seq from sqlite_sequence order by name'),
        ));
    }

    /**
     * @param  list<string>  $dirty
     */
    private function resetMysql(array $dirty): void
    {
        $now = [];

        foreach (self::counters($this->session) as $counter) {
            $now[$counter->name] = $counter->value;
        }

        // InnoDB keeps an advanced AUTO_INCREMENT on an emptied table (a rolled-back insert, a
        // delete), so every counter that moved goes back to where the migrate left it.
        $moved = array_values(array_filter(
            $this->counters,
            static fn (KeyCounter $counter): bool => ($now[$counter->name] ?? null) !== $counter->value,
        ));

        if ($dirty === [] && $moved === []) {
            return;
        }

        $grammar = $this->session->getQueryGrammar();

        // DELETE plus a counter rewind costs half a TRUNCATE (which is DDL: drop and recreate).
        $this->session->statement('set foreign_key_checks = 0');

        try {
            foreach ($dirty as $table) {
                $this->session->delete('delete from '.$grammar->wrapTable($table));
            }

            foreach ($moved as $counter) {
                $this->session->statement('alter table '.$grammar->wrapTable($counter->name).' auto_increment = '.$counter->value);
            }
        } finally {
            $this->session->statement('set foreign_key_checks = 1');
        }
    }

    /**
     * @param  list<string>  $dirty
     */
    private function resetPostgres(array $dirty): void
    {
        $grammar = $this->session->getQueryGrammar();

        if ($dirty !== []) {
            $this->session->statement('truncate table '.implode(', ', array_map(
                static fn (string $table): string => $grammar->wrapTable($table),
                $dirty,
            )).' cascade');
        }

        if ($this->counters === []) {
            return;
        }

        // Every sequence back to its post-migrate state in one round trip — the ones a rolled-back
        // insert drew from included. Reading them first would cost the same round trip.
        $this->session->select('select '.implode(', ', array_map(
            static fn (KeyCounter $counter): string => 'setval('.$grammar->quoteString($counter->name).'::regclass, '
                .$counter->value.', '.($counter->called ? 'true' : 'false').')',
            $this->counters,
        )));
    }

    /**
     * @param  list<string>  $dirty
     */
    private function resetSqlite(array $dirty): void
    {
        if ($dirty !== []) {
            $grammar = $this->session->getQueryGrammar();

            // Put the switch back as the catalogue found it — on, unless the suite runs with
            // foreign keys off.
            $foreignKeys = (int) $this->session->scalar('pragma foreign_keys');
            $this->session->statement('pragma foreign_keys = off');

            try {
                foreach ($dirty as $table) {
                    $this->session->delete('delete from '.$grammar->wrapTable($table));
                }
            } finally {
                $this->session->statement('pragma foreign_keys = '.($foreignKeys === 1 ? 'on' : 'off'));
            }
        }

        if (self::sqliteCounters($this->session) == $this->counters) {
            return;
        }

        $this->session->delete('delete from sqlite_sequence');

        foreach ($this->counters as $counter) {
            $this->session->insert('insert into sqlite_sequence (name, seq) values (?, ?)', [$counter->name, $counter->value]);
        }
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    private static function tablesWithRows(Connection $connection, array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $grammar = $connection->getQueryGrammar();
        $from = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? ' from dual' : '';

        // One round trip for every table; the names come from the catalogue, never from a test.
        $sql = implode(' union all ', array_map(
            static fn (string $table): string => 'select '.$grammar->quoteString($table).' as name'.$from
                .' where exists (select 1 from '.$grammar->wrapTable($table).')',
            $tables,
        ));

        return array_values(array_map(static fn (object $row): string => (string) self::field($row, 'name'), $connection->select($sql)));
    }

    /**
     * One column of a result row — a `stdClass` the engine named the fields of.
     */
    public static function field(object $row, string $column): mixed
    {
        return get_object_vars($row)[$column] ?? null;
    }
}
