<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The polymorphic-column seam, guarded from source tokens: a package's
 * `database/migrations` must reach every morph column through the toolkit's
 * `morphKey()` macro, never through a raw `$table->morphs()` and its family.
 *
 * ## The bug this exists to stop from coming back
 *
 * The fleet migrated all 47 outbound morph columns off raw `$table->morphs($name)` onto
 * `$table->morphKey($name, KeyType::fromConfig('<pkg>.key_type'), …)`, so a host on uuid/ulid
 * keys can flip its whole graph coherently. Raw `morphs()` hardcodes a `bigint` id, and on
 * SQLite type affinity that silently swallows a uuid string as TEXT — the break never
 * surfaces until a strict engine (Postgres) rejects it in production. **Nothing in the
 * language stops migration N+1 from typing `$table->morphs('likeable')` again** and reopening
 * exactly that hole. This pin is that stop, derived once here so a *new* package cannot
 * introduce column 48 either.
 *
 * ## Why scanning `database/migrations` is correctly scoped
 *
 * The `morphKey` macro is *implemented* on top of `$table->morphs()` / `uuidMorphs()` /
 * `ulidMorphs()` — but that implementation lives in the toolkit's `BlueprintMacros.php`, under
 * `src/`, never under a package's `database/migrations`. Pointing the scan at the migrations
 * directory alone means the only legitimate raw morph call in the whole graph is out of scope
 * by construction; a raw call *inside a migration* is always the regression this bans.
 *
 * ## Token-aware, not a substring grep
 *
 * A raw call is `->morphs(` — a `T_STRING` in {@see self::RAW_MORPHS} reached through an object
 * operator and immediately opening a paren. Three near-misses stay green because tokens, not
 * text, decide:
 *
 *   - `->morphKey(` is a different method name, not in the family — the seam itself never trips
 *     its own guard;
 *   - a **docblock or comment** mentioning `morphs(` is a `T_COMMENT` / `T_DOC_COMMENT`,
 *     dropped before matching (the same distinction that caught media #27 for the config
 *     scraper — a regex over raw text was satisfied by prose and stayed green);
 *   - a **string literal** `'morphs('` is a `T_CONSTANT_ENCAPSED_STRING`, not a `T_STRING`
 *     reached through `->`, so it is never a call.
 *
 * ## Non-vacuous by construction
 *
 * A pin that can only pass is not a pin. A **missing** directory fails, and a directory that
 * exists but yields **zero** scannable migration files fails too — a scan of nothing cannot
 * catch a raw morph, so it must not report success. A package with migrations but no morph
 * columns legitimately passes: it scanned real files and found no violation. (Corollary for
 * adoption: a package with *no* migrations must not call this preset — there is nothing for it
 * to guard, and pointed at an absent directory it would, correctly, fail.)
 *
 * This is the assertion behind {@see ArchPresets::morphColumnsUseTheSeam()}.
 */
final class MorphSeam
{
    /**
     * Every raw Blueprint morph helper the `morphKey` seam replaces. All six hardcode an id
     * key type at schema-build time — `morphs`/`nullableMorphs` to `bigint`, and the
     * `uuid`/`ulid` variants to their namesake — which is the decision the seam exists to move
     * into config. Any of them in a migration bypasses the seam, so all six are banned, not
     * just the `bigint` pair. Public so a consumer can reuse the exact list.
     *
     * @var list<string>
     */
    public const array RAW_MORPHS = [
        'morphs',
        'nullableMorphs',
        'uuidMorphs',
        'nullableUuidMorphs',
        'ulidMorphs',
        'nullableUlidMorphs',
    ];

    public static function assert(string $migrationsDir): void
    {
        Assert::assertDirectoryExists($migrationsDir, "Migrations directory does not exist: {$migrationsDir}");

        $files = self::migrationFiles($migrationsDir);

        Assert::assertNotSame(
            [],
            $files,
            "No migration files were scanned under {$migrationsDir}, so this pin could not have "
            .'caught a raw morphs() call. A package with no migrations must not adopt '
            .'morphColumnsUseTheSeam(); point it at a directory that actually holds migrations.',
        );

        $violations = [];

        foreach ($files as $file) {
            $tokens = self::meaningfulTokens((string) file_get_contents($file));

            foreach (self::rawMorphCalls($tokens) as $call) {
                $violations[] = self::relative($migrationsDir, $file).": {$call}()";
            }
        }

        sort($violations);

        Assert::assertSame(
            [],
            $violations,
            'These migrations emit a raw morph column instead of the morphKey seam: '
            .implode(', ', $violations)
            .'. Replace $table->morphs($name) with '
            ."\$table->morphKey(\$name, KeyType::fromConfig('<pkg>.key_type'), nullable: …) so the "
            .'id key type follows the host config — a hardcoded bigint id breaks uuid/ulid hosts '
            .'on a strict engine, and SQLite type affinity hides it.',
        );
    }

    /**
     * Every raw morph helper actually **called** in the file — `->morphs(` where the name is a
     * `T_STRING` in {@see self::RAW_MORPHS}, reached through an object operator (so it is a
     * method call on the Blueprint, not a bare function or a `::` reference) and immediately
     * followed by `(`.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<string>
     */
    private static function rawMorphCalls(array $tokens): array
    {
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if ($id !== T_STRING || ! in_array($text, self::RAW_MORPHS, true)) {
                continue;
            }

            $arrow = $tokens[$i - 1] ?? null;

            // Must be a method call: `$table->morphs(`. A bare `morphs(` or `Foo::morphs(` is
            // not the Blueprint helper this bans.
            if ($arrow === null || ! in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;

            if ($next === null || $next[1] !== '(') {
                continue;
            }

            $found[] = $text;
        }

        return array_values(array_unique($found));
    }

    /**
     * Tokenize and drop comments/docblocks/whitespace, so a docblock mentioning `morphs()` —
     * such as this class's own — never trips the ban. Only real code counts.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private static function meaningfulTokens(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    continue;
                }

                $tokens[] = [$token[0], $token[1]];

                continue;
            }

            $tokens[] = [null, $token];
        }

        return $tokens;
    }

    /**
     * The migration files under a directory: published migrations (`*.php`) and the
     * publish-only stubs (`*.stub`) that ship the same schema, so a raw morph hiding in a stub
     * is caught too.
     *
     * @return list<string>
     */
    private static function migrationFiles(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $name = strtolower($file->getFilename());

            if ($file->isFile() && (str_ends_with($name, '.php') || str_ends_with($name, '.stub'))) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private static function relative(string $migrationsDir, string $file): string
    {
        return ltrim(str_replace(rtrim($migrationsDir, '/'), '', $file), '/');
    }
}
