<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The both-directions config-key contract, token-scraped from source.
 *
 * Forward: every `config('<prefix>.…')` key the code reads must be shipped in the
 * config file. This kills the class where the code read `shops.payments.*` while the
 * file shipped `payment.*` — a whole feature silently disabled, its test suite green
 * only because it set the same wrong key.
 *
 * Reverse: every shipped leaf key must be read somewhere. This kills the class of a
 * shipped, documented key that nothing reads — a size cap that never applied, an
 * escalation rule read by no code.
 *
 * Both directions tolerate reading a parent wholesale (`config('pkg.rp')` covers the
 * subtree for *forward*), but the reverse direction is deliberately stricter: a parent
 * read does NOT prove a specific leaf is used, so per-leaf proof must come from an exact
 * read or a `sectionVariables` offset read. That is what makes a dead sub-key detectable.
 */
final class ConfigContract
{
    /**
     * @param  string|list<string>  $srcDirs
     * @param  array{
     *     excludeFromReverse?: list<string>,
     *     sectionVariables?: array<string, array<string, string>>,
     *     extraReadPrefixes?: list<string>,
     *     allowUnread?: list<string>,
     *     allowUnshipped?: list<string>,
     *     reverse?: bool,
     * }  $options
     */
    public static function assert(string $configPath, string|array $srcDirs, ?string $prefix = null, array $options = []): void
    {
        $prefix ??= basename($configPath, '.php');

        $excludeFromReverse = $options['excludeFromReverse'] ?? [];
        $sectionVariables = $options['sectionVariables'] ?? [];
        $extraReadPrefixes = $options['extraReadPrefixes'] ?? [];
        $allowUnread = $options['allowUnread'] ?? [];
        $allowUnshipped = $options['allowUnshipped'] ?? [];
        $reverse = $options['reverse'] ?? true;

        $shipped = ConfigLeaves::forFile($configPath, $prefix);

        $scraper = new TokenScraper($prefix, $extraReadPrefixes);

        $readsAll = [];
        $readsForReverse = [];
        $interpolations = [];

        foreach (self::sourceFiles($srcDirs) as $file) {
            $basename = basename($file);
            $scraped = $scraper->scrape($file, $sectionVariables[$basename] ?? []);

            foreach ($scraped->reads as $key) {
                $readsAll[] = $key;

                if (! in_array($basename, $excludeFromReverse, true)) {
                    $readsForReverse[] = $key;
                }
            }

            foreach ($scraped->interpolations as $snippet) {
                $interpolations[] = "{$basename}: {$snippet}";
            }
        }

        $readsAll = array_values(array_unique($readsAll));
        $readsForReverse = array_values(array_unique($readsForReverse));

        self::assertNoInterpolations($interpolations, $prefix);
        self::assertForward($readsAll, $shipped, $allowUnshipped, $prefix);

        if ($reverse) {
            self::assertReverse($shipped, $readsForReverse, $allowUnread, $prefix);
            self::assertAllowUnreadIsLive($allowUnread, $shipped, $readsForReverse);
        }

        self::assertAllowUnshippedIsLive($allowUnshipped, $readsAll, $shipped);
    }

    /**
     * An interpolated key has **no allow-list**, and the message must not pretend otherwise.
     *
     * It used to advise "add it to allowUnshipped/allowUnread" — an escape that does not
     * exist. This check runs *before* both the forward and reverse checks and consults
     * neither list, so a reader who took the advice literally watched the identical error
     * tell them again to do the thing they had just done. An error message that names a
     * non-existent remedy is worse than one that names none: it costs the reader the time to
     * discover it is lying.
     *
     * @param  list<string>  $interpolations
     */
    private static function assertNoInterpolations(array $interpolations, string $prefix): void
    {
        Assert::assertSame(
            [],
            $interpolations,
            "Interpolated or concatenated config keys under '{$prefix}.' cannot be checked: "
            .implode('; ', $interpolations)
            .'. There is no allow-list for this — allowUnshipped and allowUnread are consulted only by the '
            .'forward and reverse checks, which run after this one. Either name each key as a literal string, '
            .'or read the parent section wholesale into a variable and index it with literal offsets '
            ."(\$section['driver']), mapping that variable through the sectionVariables option.",
        );
    }

    /**
     * @param  list<string>  $readsAll
     * @param  list<string>  $shipped
     * @param  list<string>  $allowUnshipped
     */
    private static function assertForward(array $readsAll, array $shipped, array $allowUnshipped, string $prefix): void
    {
        $missing = [];

        foreach ($readsAll as $read) {
            if (! self::coveredForward($read, $shipped) && ! in_array($read, $allowUnshipped, true)) {
                $missing[] = $read;
            }
        }

        sort($missing);

        Assert::assertSame(
            [],
            $missing,
            "The code reads config keys under '{$prefix}.' that the config file does not ship: "
            .implode(', ', $missing).'. Ship them, fix the read, or add them to allowUnshipped.',
        );
    }

    /**
     * @param  list<string>  $shipped
     * @param  list<string>  $readsForReverse
     * @param  list<string>  $allowUnread
     */
    private static function assertReverse(array $shipped, array $readsForReverse, array $allowUnread, string $prefix): void
    {
        $unread = [];

        foreach ($shipped as $leaf) {
            if (! self::coveredReverse($leaf, $readsForReverse) && ! in_array($leaf, $allowUnread, true)) {
                $unread[] = $leaf;
            }
        }

        sort($unread);

        Assert::assertSame(
            [],
            $unread,
            "The config file ships '{$prefix}.' keys that nothing reads: "
            .implode(', ', $unread).'. Remove them, wire them up, or add them to allowUnread.',
        );
    }

    /**
     * Every allowUnread entry must silence a real, currently-unread shipped key —
     * otherwise the allow-list has rotted and hides nothing.
     *
     * @param  list<string>  $allowUnread
     * @param  list<string>  $shipped
     * @param  list<string>  $readsForReverse
     */
    private static function assertAllowUnreadIsLive(array $allowUnread, array $shipped, array $readsForReverse): void
    {
        foreach ($allowUnread as $entry) {
            Assert::assertTrue(
                in_array($entry, $shipped, true) && ! self::coveredReverse($entry, $readsForReverse),
                "Stale allowUnread entry '{$entry}': it is not a shipped-but-unread key. Remove it.",
            );
        }
    }

    /**
     * Every allowUnshipped entry must silence a real, currently-unshipped read.
     *
     * @param  list<string>  $allowUnshipped
     * @param  list<string>  $readsAll
     * @param  list<string>  $shipped
     */
    private static function assertAllowUnshippedIsLive(array $allowUnshipped, array $readsAll, array $shipped): void
    {
        foreach ($allowUnshipped as $entry) {
            Assert::assertTrue(
                in_array($entry, $readsAll, true) && ! self::coveredForward($entry, $shipped),
                "Stale allowUnshipped entry '{$entry}': it is not a read-but-unshipped key. Remove it.",
            );
        }
    }

    /**
     * A read is shipped if it names a leaf, a parent of a leaf, or a path into a leaf.
     *
     * @param  list<string>  $shipped
     */
    private static function coveredForward(string $read, array $shipped): bool
    {
        foreach ($shipped as $leaf) {
            if ($leaf === $read
                || str_starts_with($leaf, $read.'.')
                || str_starts_with($read, $leaf.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A leaf is read only by an exact read or a read into its subtree — never by a
     * read of one of its ancestors (a wholesale parent read proves nothing per-leaf).
     *
     * @param  list<string>  $reads
     */
    private static function coveredReverse(string $leaf, array $reads): bool
    {
        foreach ($reads as $read) {
            if ($read === $leaf || str_starts_with($read, $leaf.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The source files to scrape: every `*.php` under each src dir, plus a sibling
     * `database/` directory when one exists (migrations and factories read config too).
     *
     * @param  string|list<string>  $srcDirs
     * @return list<string>
     */
    private static function sourceFiles(string|array $srcDirs): array
    {
        $directories = [];

        foreach ((array) $srcDirs as $dir) {
            Assert::assertDirectoryExists($dir, "Source directory does not exist: {$dir}");
            $directories[$dir] = true;

            $database = dirname($dir).'/database';

            if (is_dir($database)) {
                $directories[$database] = true;
            }
        }

        $files = [];

        foreach (array_keys($directories) as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[$file->getPathname()] = true;
                }
            }
        }

        $paths = array_keys($files);
        sort($paths);

        return $paths;
    }
}
