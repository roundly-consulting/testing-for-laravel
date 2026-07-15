# Testing for Laravel

Dev-only test machinery for Laravel packages and applications: a Testbench base test
case, before-boot model swaps, provider-class migration loading, and Pest expectations
that are built so they can **always fail** — no vacuous green.

> Status: early build (Phase A). Today it ships the base test cases and the structural
> migration-order pin. Config-contract, about-secret, model-swap and arch-preset
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
