# Changelog

All notable changes to `testing-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.0.2 - 2026-10-10

### Fixed

- The config contract counts keys read through package-toolkit-for-laravel 1.2's new readers:
  `Config::list()`, and `float()`, `string()` and `list()` on a `ConfigValidator`
  (`Config::using(…)->string()`, `Config::for(…)->list()`, `self::validator()->float()`, …).
  Drop the `allowUnread` / `extraReadPrefixes` entries you added for them.

## 1.0.1 - 2026-10-05

### Changed

- `toHaveRunnableMigrationOrder()` now fails when there is nothing to order: an empty
  directory, a directory of `*.php.stub` files only, or migrations with no
  `Schema::create()`/`Schema::table()` block — even with `foreignKeys: 0`. Point it at the
  directory that holds your migrations.
- The order pin follows each table's life through `up()`: a second `Schema::create()` without a
  drop in between now fails, and so does a key onto (or an ALTER of) a table already dropped or
  renamed. `down()` is ignored.
- `ModelSeam` (`modelsResolveThroughSeam()`) also flags `static::where()`, `self::create()`,
  `static::firstOrCreate()` and other query calls in a model's static methods, plus `new self`
  anywhere in a model. Route them through your seam. Static hooks such as `static::creating()`
  in `booted()` are unaffected.
- `morphColumnsUseTheSeam()` also bans `numericMorphs()` and `nullableNumericMorphs()`. Use
  `morphKey()` instead.
- `noDebuggingLeftovers()` matches debug calls in any letter case (`DD()`, `Var_Dump()`).
- `toApplyOnConnection()` / `toRejectBrokenOrderOnConnection()` refuse a real-engine connection
  that is not an isolated probe — Testbench's stock `mariadb`, or the suite's own `testing`
  connection on a Postgres/MySQL leg. Use the `pgsql` / `mysql` probes; SQLite connections are
  still accepted. `DriverMatrix::isProbe()` tells you whether a connection qualifies.
- `LockRecordingGrammar` throws on a non-SQLite connection. Gate grammar-based lock tests to
  SQLite; on Postgres and MySQL the engine takes the real lock.
- `LockRecordingBuilder` records a lock when its query runs, at the transaction depth it runs
  at. A locking query that never runs records nothing.
- On a SQLite leg, `TESTING_DB_*` now describes the Postgres probe only. To reach MySQL, run a
  MySQL leg (`TESTING_DB_DRIVER=mysql`).
- `runtimeRequireIsWhitelisted()` allows Composer platform packages (`composer-runtime-api`,
  `composer-plugin-api`, `lib-*`, `php-64bit`, …) without an `$alsoAllow` entry.
- Maintenance: `composer.json` `homepage` and `support.docs` point at the documentation site.
- Documentation: the README installs with `--with-all-dependencies` (so Composer can move a fresh
  app's `phpunit/phpunit` to a version Pest 4 supports) and loads its hero image from an
  absolute URL.

### Fixed

- The order pin reads `Schema::connection(...)->create()`/`table()` blocks and
  `Schema::rename()` instead of failing them as unparsed or uncreated.
- Named-class migrations load like the Laravel migrator loads them, and loading one twice no
  longer crashes the run with a class redeclaration.
- A provider's migrations directory is looked up no higher than the package root, so a package
  installed under a host's `vendor/` never resolves to the host's own migrations.
- Under `pest --parallel` on a real engine, each worker gets its own database and probe
  namespace, so one worker's teardown no longer drops another worker's tables.
  `DriverMatrix::probeNamespace()` returns the current worker's probe namespace.
- A SQLite leg with a Postgres location no longer stalls for minutes probing MySQL at that
  Postgres port.
- The config contract counts `config(key: '…')` and other named-key reads, and keys read
  through `getMany()`.
- `noDebuggingLeftovers()` exemptions can name traits, enums and interfaces, by name or by
  namespace.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Dev-only test machinery for Laravel packages and apps — install with `composer require --dev`;
  nothing reaches production.
- Pest expectations that register automatically, built so every assertion can actually fail
  (no vacuous green).
- A structural migration-order pin, `toHaveRunnableMigrationOrder()`, that catches foreign keys
  pointing at tables created later — even on SQLite. It orders per `Schema` block, reads every
  Laravel foreign-key form (`foreignIdFor()`, named arguments included) and takes
  `externalTables` for tables the set builds on but does not create.
- A real-engine runner (`toApplyOnConnection()`) with a negative control
  (`toRejectBrokenOrderOnConnection()`) that counts only ordering errors as a rejection and
  fails on an unreachable engine.
- Publish-only migration guards: `toNotAutoLoadMigrations()` and `toPublishMigrationsTimestamped()`.
- A both-directions config contract, `toSatisfyConfigContract()`, that flags undocumented and
  unused config keys — read from `config()`, the `Config` facade and the repository in any
  spelling, from package-toolkit-for-laravel's readers (static and chained), in PHP and in
  Blade views.
- A secret-safe `about` capture (`toLeakNoSecrets()`) and a model-swap proof
  (`toHonourModelSwap()`, `toBeSwappableVia()`).
- A facade-contract pin for the Actions → Manager → Facade convention:
  `toDocumentItsRoot()` (a `final` facade, a class-string accessor, and a `@method static`
  docblock that matches its root, counted depth-aware), `toBeFakeable()` (a real `fake()`
  whose fake is a proper subtype of the root and replaces it for dependency injection too) and
  `toReachEveryAction()` (every non-`@internal` action reachable through the facade and its
  sub-accessors), each with a static `Assert` twin.
- Nine composable architecture presets in `ArchPresets`, from strict types and final-by-default
  to a runtime-dependency whitelist, a no-debugging-leftovers check and
  `modelsGoThroughTheFacade()`, which keeps model methods and traits from calling actions
  behind the fake's back — it scans `Models`/`Concerns`/`Traits`, every Eloquent model anywhere
  in the package (per-area folders included) and every package trait those models use,
  recursively.
- `PackageTestCase`, a Testbench base case with foreign keys on, provider-based migration
  loading and model swaps applied before boot.
- Lock recorders that make `lockForUpdate()` observable on SQLite, and a `DriverMatrix` for
  running a suite on SQLite, PostgreSQL and MySQL.
