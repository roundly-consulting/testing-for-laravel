# Changelog

All notable changes to `testing-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
  spelling, in PHP and in Blade views.
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
