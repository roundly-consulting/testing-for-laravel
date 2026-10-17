# Changelog

All notable changes to `testing-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- `PackageTestCase::keepsMigratedSchema()` no longer fails every database test with
  `no such table: migrations` in a test case whose `migrationSources()` is empty. Nothing is
  migrated there, so nothing is kept: the test case stays on the drop-per-test reset, and a fixture
  table a test builds by hand is still gone before the next test, on every engine. The schema
  snapshot also keeps nothing when no migrations table exists after migrating.

## 1.6.0 - 2026-10-17

### Added

- `PackageTestCase::keepsMigratedSchema()`: return `true` and a worker migrates once per schema
  signature instead of before every test, then resets only the data between tests. After each test
  it empties the tables the test wrote and puts every key counter (MySQL `AUTO_INCREMENT`, Postgres
  sequences, SQLite `sqlite_sequence`) back where the migrate left it. There is still no wrapping
  transaction, so `transactionDepth` and forked racers behave as before. A catalogue check after
  every test (columns, indexes, constraints, views, triggers, the migrations table, SQLite's
  foreign-key switch) catches a test that changed the schema, and the next test migrates from
  scratch. Migrations that write rows are detected after the first migrate, and that schema keeps
  the drop-per-test reset. The worker drops its tables when it exits. Supported on MySQL, MariaDB,
  PostgreSQL and in-memory SQLite; anything else keeps the drop-per-test reset. Off by default.
  tickets-for-laravel (9 migration sources), where the harness started, went from 1061 migrates per
  run to 37 and its CI MySQL leg from 32 minutes to under 2. A forked child must end with SIGKILL,
  never `exit()`: a normal exit closes the database sessions it shares with the parent.
- `PackageTestCase::schemaSignatureExtras()`: whatever else your migrations read that the
  signature cannot see (an env var, a static flag), so a test that would migrate differently never
  reuses another test's schema. The signature already covers the connection, the migration sources,
  the loaded providers, the whole config and the schema builder's static defaults.

### Changed

- On in-memory SQLite the teardown no longer runs Testbench's `migrate:rollback` for each
  `migrationSources()` entry. With no `down()` to call, it only deleted the migrations rows of a
  database that vanishes with its connection anyway. That was about a fifth of a SQLite suite's
  time (tickets-for-laravel: 83 s to 60 s, no opt-in needed). A migrator a test adds itself
  (`loadMigrationsFrom()`, `loadLaravelMigrations()`) still rolls back, and a `RefreshDatabase`
  suite is untouched.
- Maintenance: the pgsql CI leg runs Postgres on tmpfs with durability off, and fails if the suite
  leaves a table behind.

## 1.5.0 - 2026-10-17

### Added

- `ArchPresets::factoriesResolveThroughSeam($factoriesDir, $srcDir, $seamDir = 'Support')`: every
  factory under `$factoriesDir` whose model is swappable must override `modelName()` to return that
  model's seam (`return LikeModel::class();`). Without the override, `Like::factory()` builds the
  packaged class after a host swapped it, on the packaged table and without the host's casts,
  scopes or events. The swappable models are read from the package's seam, as below. The case fails
  on a missing directory, a seam with no swappable model, a directory with no factory class,
  factories none of which builds a swappable model, and a factory whose model it cannot read.
  Opt-in: adopt it together with the overrides.

### Changed

- `ArchPresets::modelsResolveThroughSeam()` also fails two bypasses outside `$seamDir`: a relation
  that names a swappable model by its packaged class (`belongsTo(Comment::class)`, a through
  model, `self::class` inside the model), and a static call that starts a query on it
  (`Comment::query()`, `Comment::where()`, `Comment::withTrashed()`, a local scope, …). Both
  relate or query the packaged class, so a host's swapped model is ignored. The swappable models
  are read from the package's own seam: every Eloquent model that a static, parameterless method
  returning `string` under `$seamDir` names beside a config key
  (`ModelResolver::for('comments.model', Comment::class)`). Each declared `$modelKeys` entry must
  be read that way, or the case fails. `instanceof Comment` and `is_a(…, Comment::class)` stay
  allowed, and docblocks and strings do not count.

## 1.4.0 - 2026-10-11

### Changed

- `ArchPresets::noLocalCryptoPrimitives()` also bans `hash_hkdf`. crypto-for-laravel 1.2.0 ships
  `Crypto::hkdf()`, which takes the algorithm and the length explicitly (a length of `0` throws
  instead of meaning one hash length). Move a local `hash_hkdf()` call to it.

## 1.3.0 - 2026-10-11

### Changed

- `ArchPresets::runtimeRequireIsWhitelisted()` fails an `$alsoAllow` entry that forgives
  nothing: a package that is not in `require` (removed, or only in `require-dev`), or one the
  policy already allows (`illuminate/*`, `ext-*`, `roundly-consulting/*`, …). A dependency
  dropped from `require` used to leave its carve-out behind, so the vendor could come back later
  without anyone arguing for it again. Remove the entries the failure names.

## 1.2.1 - 2026-10-10

### Fixed

- The config contract counts keys read through package-toolkit-for-laravel 1.3's secret
  readers: `Config::secret()`, `Config::requireSecret()` and `Config::secretList()`, and the
  same three on a `ConfigValidator` (`Config::using(…)->secret()`, `Config::for(…)->secretList()`,
  `self::validator()->requireSecret()`, …). A package that moved a setting to them failed its
  config-contract test with the key reported as unread. Same-named methods on anything that is
  not a toolkit reader (a vault client's `secret()`) still do not count.

## 1.2.0 - 2026-10-10

### Added

- `expect(Facade::class)->toRedactSensitiveArguments(methods: N)` (and
  `Assert::facadeRedactsSensitiveArguments()`): a call through the facade must keep every root
  argument marked `#[SensitiveParameter]` out of every stack frame, and every other argument
  visible in the facade's own frame. It swaps in a root that throws, calls each method through
  the facade (positionally and with named arguments) with `zend.exception_ignore_args` off, and
  reads the frame arguments. A stock Laravel facade fails it: its `__callStatic` frame holds the
  raw arguments. So does a facade that hides every argument. `methods: N` pins how many root
  methods take a secret (at least 1). On a contract accessor, the class bound under it must mark
  the same parameters.
- `ArchPresets::noVendorNamespace($prefixes, $srcDir, $ignoring)`: nothing under `$srcDir`
  (default `src/`) may name a namespace under one of `$prefixes`, e.g.
  `noVendorNamespace(['GuzzleHttp', 'App'])`. Pest's `->not->toUse('GuzzleHttp')` stays green
  when `src/` imports `GuzzleHttp\Psr7\Utils`, because it only sees classes under an installed
  PSR-4 root. It also misses vendors that are not installed and host names like `App\`. This
  preset reads the names from source and resolves them through the file's namespace and `use`
  imports, so it catches all three. It fails on a missing directory or one with no PHP file.

## 1.1.0 - 2026-10-10

### Added

- Pest 5 support: the package now installs with `pestphp/pest` `^4.0|^5.0`, so a Pest 5 suite
  can `require --dev` it. Pest 5 needs PHPUnit 13, which means Laravel 13; Pest 4 keeps working
  on Laravel 12 and 13. Every expectation and arch preset behaves the same on both majors.

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
