# Testing for Laravel

Dev-only test machinery for Laravel packages and applications: a Testbench base test
case, before-boot model swaps, provider-class migration loading, and Pest expectations
that are built so they can **always fail** — no vacuous green.

> Status: early build (Phase B). Today it ships the base test cases, the structural
> migration-order pin, a real-engine migration runner with a negative control, and the
> publish-only migration guards. Config-contract, about-secret, model-swap and arch-preset
> assertions land in later phases.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- Pest 4 (the assertions ship as Pest expectations)

## Installation

```bash
composer require --dev roundly-consulting/testing-for-laravel
```

The Pest expectations register automatically through `extra.pest.plugins`. Suites that
disable plugin discovery can register them explicitly in `tests/Pest.php`:

```php
use RoundlyConsulting\Testing\Expectations\Expectations;

Expectations::register(); // idempotent
```

## The migration-order pin

`expect(...)->toHaveRunnableMigrationOrder()` is the canonical, documented API. It parses
the foreign keys out of your migration **source** and asserts every referenced table is
created before the migration that references it — a *structural* check, because SQLite
happily creates a table that points at a missing parent and only complains at insert time.

```php
it('has a runnable migration order', function (): void {
    expect(database_path('migrations'))->toHaveRunnableMigrationOrder();
});

// Pin the edge count so the check can never pass over an empty parse:
expect(__DIR__.'/../../database/migrations')->toHaveRunnableMigrationOrder(foreignKeys: 19);

// Resolve non-literal table names (Schema::create($var), ->constrained(Class::method())):
expect($dir)->toHaveRunnableMigrationOrder(tableResolvers: ['$tableName' => 'media']);
```

It understands every foreign-key form the fleet uses: `->constrained('table')`, bare
`->constrained()` (parent derived from the column), long-hand `->references('id')->on('table')`,
and `->constrained(Class::method())` via `tableResolvers`. It also pins that a
`Schema::table()` ALTER sorts after its CREATE and that a self-referencing key sorts with
its own migration. An unparseable declaration **fails** rather than being silently dropped.

Plain PHPUnit callers can use the static escape hatch:

```php
use RoundlyConsulting\Testing\Assert;

Assert::migrationsRunInDependencyOrder(database_path('migrations'), expectedForeignKeys: 19);
```

## The real-engine runner and its negative control

The structural pin is engine-independent, but the definitive proof is running the migrations
against a real database. `expect(...)->toApplyOnConnection()` applies every migration, in
directory order, against a live connection from an empty database — on pgsql/mysql that means
every foreign key must land on a table that already exists.

```php
it('applies clean on postgres', function (): void {
    expect(database_path('migrations'))->toApplyOnConnection('pgsql');
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no pgsql');
```

A green foreign-key test proves nothing until you have watched the engine *reject* the broken
order. `toRejectBrokenOrderOnConnection()` is that negative control: `$reorder` deliberately
breaks the order, and the expectation passes only if the engine refuses to apply it. If the
engine **accepts** the broken order — because it does not enforce foreign keys (SQLite) — the
check is vacuous and fails loudly, so you can never mistake "sqlite doesn't care" for a pass.

```php
expect(database_path('migrations'))->toRejectBrokenOrderOnConnection(
    fn (array $files): array => array_reverse($files),
    'pgsql',
);
```

Gate these on a driver being present so a suite with no pgsql/mysql *skips visibly* rather than
passing green — `MigrationRunner::connectionIsAvailable('pgsql')` (mirrored on the base test case
as `connectionAvailable()`) is a boolean you can hand to Pest's `->skip()`.

## Publish-only migration guards

The fleet publishes migrations timestamped rather than auto-loading them (auto-load + publish
runs both copies — a duplicate-table failure). Two expectations pin the policy on a service
provider:

```php
// The package's database/migrations must NOT be registered with the migrator:
expect(PasskeysServiceProvider::class)->toNotAutoLoadMigrations();

// Every source publishes to a timestamped database_path('migrations/<Y_m_d_His>_<name>.php'):
expect(PasskeysServiceProvider::class)->toPublishMigrationsTimestamped('passkeys-migrations', 3);
```

The migrations directory defaults to the provider's own (resolved by reflection); pass an
explicit path to override. Both mirror on `Assert::doesNotAutoLoadMigrations()` and
`Assert::publishesMigrationsTimestamped()`.

## The package base test case

`PackageTestCase` replaces the near-identical `tests/TestCase.php` copied into every
roundly package. It runs against an in-memory SQLite database with foreign-key
constraints on, loads migrations **by provider class** (never by filename), and applies
config and model swaps before the providers boot.

```php
use RoundlyConsulting\Testing\PackageTestCase;

final class TestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [CryptoServiceProvider::class, PasskeysServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [PasskeysServiceProvider::class];
    }

    protected function configBeforeBoot(): array
    {
        return ['passkeys.rp.id' => 'example.test'];
    }
}
```

Swap a configured model before boot with `$this->swapModel('media.media_model', CustomMedia::class)`
in `defineEnvironment()`.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
