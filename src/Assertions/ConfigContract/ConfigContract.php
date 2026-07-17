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
        $dynamicSections = [];
        $origins = [];

        foreach (self::sourceFiles($srcDirs) as $file) {
            $basename = basename($file);
            $scraped = $scraper->scrape($file, $sectionVariables[$basename] ?? []);

            foreach ($scraped->reads as $key) {
                $readsAll[] = $key;

                $origins[$key] ??= [];
                $origins[$key][] = in_array($key, $scraped->prefixedReads, true)
                    ? "{$basename} (counted because it matches extraReadPrefixes)"
                    : $basename;

                if (! in_array($basename, $excludeFromReverse, true)) {
                    $readsForReverse[] = $key;
                }
            }

            foreach ($scraped->interpolations as $snippet) {
                $interpolations[] = "{$basename}: {$snippet}";
            }

            foreach ($scraped->dynamicSections as $entry) {
                [$path, $snippet] = explode('|', $entry, 2);
                $dynamicSections[] = [$path, "{$basename}: {$snippet}"];
            }
        }

        $readsAll = array_values(array_unique($readsAll));
        $readsForReverse = array_values(array_unique($readsForReverse));

        // The one check that must still abort. An unresolvable key means the read-set is
        // *incomplete*, so the forward and reverse findings computed from it would be
        // fiction — reverse in particular would invent dead keys. The two directions below
        // share one sound read-set, which is exactly why both of them can be reported.
        self::assertNoInterpolations($interpolations, $prefix);

        $report = self::forwardProblems($readsAll, $shipped, $allowUnshipped, $prefix, $origins);

        if ($reverse) {
            $report = array_merge(
                $report,
                self::reverseProblems($shipped, $readsForReverse, $allowUnread, $prefix, $dynamicSections),
                self::staleAllowUnread($allowUnread, $shipped, $readsForReverse),
            );
        }

        $report = array_merge($report, self::staleAllowUnshipped($allowUnshipped, $readsAll, $shipped));

        Assert::assertSame(
            [],
            $report,
            $report === [] ? '' : "The '{$prefix}.' config contract failed:\n\n".implode("\n\n", $report)."\n",
        );
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
     * The forward direction: every key the code reads must be shipped.
     *
     * Each finding names *where* the read came from. That is not decoration. On
     * `kubernetes-api`, `kubernetes.` is the Kubernetes API's own namespace, so an
     * `extraReadPrefixes` entry of `kubernetes.` silently promoted a literal
     * `kubernetes.io/tls` — an annotation key, not a config key — into a config read, and
     * the forward direction failed on a key no one had ever meant to read. The finding said
     * only that the key was unshipped, and diagnosing it took hours. Naming the file and the
     * reason turns that into seconds.
     *
     * @param  list<string>  $readsAll
     * @param  list<string>  $shipped
     * @param  list<string>  $allowUnshipped
     * @param  array<string, list<string>>  $origins
     * @return list<string>
     */
    private static function forwardProblems(array $readsAll, array $shipped, array $allowUnshipped, string $prefix, array $origins): array
    {
        $missing = [];

        foreach ($readsAll as $read) {
            if (! self::coveredForward($read, $shipped) && ! in_array($read, $allowUnshipped, true)) {
                $missing[] = $read;
            }
        }

        if ($missing === []) {
            return [];
        }

        sort($missing);

        $lines = array_map(
            static fn (string $key): string => "  - {$key}  [read in ".implode(', ', array_unique($origins[$key] ?? ['?'])).']',
            $missing,
        );

        return [
            "FORWARD — the code reads '{$prefix}.' keys that the config file does not ship:\n"
            .implode("\n", $lines)
            ."\nShip them, fix the read, or add them to allowUnshipped. If a key above is not a config key at all, "
            .'an extraReadPrefixes entry is too broad — name the keys exactly instead of blanket-prefixing.',
        ];
    }

    /**
     * The reverse direction: every shipped leaf must be read.
     *
     * @param  list<string>  $shipped
     * @param  list<string>  $readsForReverse
     * @param  list<string>  $allowUnread
     * @param  list<array{0: string, 1: string}>  $dynamicSections
     * @return list<string>
     */
    private static function reverseProblems(array $shipped, array $readsForReverse, array $allowUnread, string $prefix, array $dynamicSections): array
    {
        $unread = [];

        foreach ($shipped as $leaf) {
            if (! self::coveredReverse($leaf, $readsForReverse) && ! in_array($leaf, $allowUnread, true)) {
                $unread[] = $leaf;
            }
        }

        if ($unread === []) {
            return [];
        }

        sort($unread);

        $problem = "REVERSE — the config file ships '{$prefix}.' keys that nothing reads:\n  - "
            .implode("\n  - ", $unread)
            ."\nRemove them, wire them up, or add them to allowUnread.";

        $hints = self::wholesaleHints($unread, $dynamicSections);

        if ($hints !== []) {
            $problem .= "\n\nSome of these sit under a section read wholesale through a dynamic key:\n  - "
                .implode("\n  - ", $hints)
                ."\nA wholesale read proves no individual leaf, by design. Assign the section to a local and "
                .'index it with literal offsets, then map that local through sectionVariables — its base path '
                .'may carry a `*` for the dynamic segment.';
        }

        return [$problem];
    }

    /**
     * The dynamic wholesale reads that sit above at least one unread leaf — the likely cause
     * of that leaf looking dead, surfaced so the reader does not have to guess.
     *
     * @param  list<string>  $unread
     * @param  list<array{0: string, 1: string}>  $dynamicSections
     * @return list<string>
     */
    private static function wholesaleHints(array $unread, array $dynamicSections): array
    {
        $hints = [];

        foreach ($dynamicSections as [$path, $snippet]) {
            foreach ($unread as $leaf) {
                if (KeyPattern::sharesPath($path, $leaf) && substr_count($path, '.') < substr_count($leaf, '.')) {
                    $hints[] = $snippet;

                    break;
                }
            }
        }

        return array_values(array_unique($hints));
    }

    /**
     * Every allowUnread entry must silence a real, currently-unread shipped key —
     * otherwise the allow-list has rotted and hides nothing.
     *
     * @param  list<string>  $allowUnread
     * @param  list<string>  $shipped
     * @param  list<string>  $readsForReverse
     * @return list<string>
     */
    private static function staleAllowUnread(array $allowUnread, array $shipped, array $readsForReverse): array
    {
        $stale = [];

        foreach ($allowUnread as $entry) {
            if (! in_array($entry, $shipped, true) || self::coveredReverse($entry, $readsForReverse)) {
                $stale[] = "Stale allowUnread entry '{$entry}': it is not a shipped-but-unread key. Remove it.";
            }
        }

        return $stale;
    }

    /**
     * Every allowUnshipped entry must silence a real, currently-unshipped read.
     *
     * @param  list<string>  $allowUnshipped
     * @param  list<string>  $readsAll
     * @param  list<string>  $shipped
     * @return list<string>
     */
    private static function staleAllowUnshipped(array $allowUnshipped, array $readsAll, array $shipped): array
    {
        $stale = [];

        foreach ($allowUnshipped as $entry) {
            if (! in_array($entry, $readsAll, true) || self::coveredForward($entry, $shipped)) {
                $stale[] = "Stale allowUnshipped entry '{$entry}': it is not a read-but-unshipped key. Remove it.";
            }
        }

        return $stale;
    }

    /**
     * A read is shipped if it names a leaf, a parent of a leaf, or a path into a leaf. A
     * driver hole in the read matches any one segment — so `pkg.providers.*.url` is shipped
     * when some `pkg.providers.<driver>.url` is, and a typo'd `pkg.providers.*.urls` is not.
     * That is what checks a `sectionVariables` base path rather than trusting it.
     *
     * @param  list<string>  $shipped
     */
    private static function coveredForward(string $read, array $shipped): bool
    {
        foreach ($shipped as $leaf) {
            if (KeyPattern::sharesPath($read, $leaf)) {
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
            if (KeyPattern::readsLeaf($read, $leaf)) {
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
