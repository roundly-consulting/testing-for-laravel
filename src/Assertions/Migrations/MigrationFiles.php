<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * The one place a migration set is turned into a list of files, in the order the
 * migrator would run them (directory sort), and a file is turned into a Migration.
 *
 * Kept apart from {@see MigrationRunner} on purpose: every assertion that claims to be
 * about "the same migration set" reads it through here, so two of them cannot silently
 * disagree about which files the set contains.
 */
final class MigrationFiles
{
    /**
     * Every migration file in $directory, directory-sorted — the order the migrator
     * applies them in. A missing directory fails rather than reading as "no migrations".
     *
     * @return list<string>
     */
    public static function sorted(string $directory): array
    {
        Assert::assertDirectoryExists($directory, "Migrations directory does not exist: {$directory}");

        $files = glob(rtrim($directory, '/').'/*.php') ?: [];
        sort($files);

        return $files;
    }

    /**
     * What each migration file returned when it was first required, by path. A file is
     * required once per process: a named-class migration declares its class, and a second
     * `require` is a fatal redeclare that takes the whole run down.
     *
     * @var array<string, mixed>
     */
    private static array $required = [];

    /**
     * Load one migration file into its Migration instance, the way `Migrator::resolvePath()`
     * does: an anonymous migration is the object the file returns; a **named-class** one (the
     * file returns nothing) is the class its name derives — `2024_01_01_000000_create_posts_table`
     * → `CreatePostsTable`. A file that yields neither fails by name — it is never skipped,
     * because a silently-skipped migration is the whole bug class these assertions exist for.
     */
    public static function load(string $file): Migration
    {
        $class = self::namedClass($file);

        if ($class !== null && self::declaredBy($class, $file)) {
            return self::instance($class, $file);
        }

        if (! array_key_exists($file, self::$required)) {
            self::$required[$file] = require $file;
        }

        $migration = self::$required[$file];

        if (is_object($migration)) {
            // A fresh instance per load, as the Migrator hands out: re-require an anonymous class
            // with a constructor (anonymous classes may be re-required), clone the rest.
            $migration = method_exists($migration, '__construct') ? require $file : clone $migration;
        } elseif ($class !== null && self::declaredBy($class, $file)) {
            return self::instance($class, $file);
        }

        if (! $migration instanceof Migration) {
            Assert::fail("Migration file `{$file}` did not return a runnable Migration instance.");
        }

        return $migration;
    }

    /**
     * The class a named-class migration file declares, by Laravel's convention: the studly
     * name after the four timestamp segments. Null when the name has none.
     */
    private static function namedClass(string $file): ?string
    {
        $segments = array_slice(explode('_', basename($file, '.php')), 4);

        return $segments === [] ? null : Str::studly(implode('_', $segments));
    }

    private static function declaredBy(string $class, string $file): bool
    {
        if (! class_exists($class, false)) {
            return false;
        }

        $declaredIn = (new ReflectionClass($class))->getFileName();

        return $declaredIn !== false && realpath($declaredIn) === realpath($file);
    }

    private static function instance(string $class, string $file): Migration
    {
        $migration = new $class;

        if (! $migration instanceof Migration) {
            Assert::fail("Migration file `{$file}` declares `{$class}`, which is not a Migration.");
        }

        return $migration;
    }

    /**
     * Apply one migration file.
     *
     * The `method_exists` guard is not ceremony: `Migration` declares no `up()` at all, and
     * `Migrator::runMigration()` guards the call with `method_exists` — so a migration that
     * forgot its `up()` is silently skipped rather than reported. Here the absence fails by
     * name instead.
     */
    public static function up(string $file): void
    {
        $migration = self::load($file);

        if (! method_exists($migration, 'up')) {
            Assert::fail(basename($file).' declares no up() — it is not a runnable migration.');
        }

        $migration->up();
    }
}
