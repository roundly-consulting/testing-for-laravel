<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Testing\Support\PhpFiles;

/**
 * A namespace ban read from source tokens, so it can fail where Pest's arch layer cannot.
 *
 * ## Why this is not `->not->toUse('GuzzleHttp')`
 *
 * Pest's arch layer expands a name into the classes under an **installed PSR-4 root** at or
 * above it, and keeps a dependency only when its symbol exists. Three bans therefore pass over
 * nothing, measured in the fleet's 2026-10 sweep:
 *
 * - **a sibling package under a vendor prefix**: `GuzzleHttp\Psr7` and `GuzzleHttp\Promise` are
 *   separate packages with their own roots, so `->not->toUse('GuzzleHttp')` stays green with
 *   `use GuzzleHttp\Psr7\Utils;` in `src/`;
 * - **a vendor that is not installed**: nothing exists to expand into, so a `use` of it is
 *   invisible — the exact vendor a ban is written to keep out;
 * - **a host namespace**: a package has no `App\` root, so `->not->toUse('App')` checks nothing.
 *
 * Tokens have no such gate. Every name is resolved the way PHP resolves it — through the file's
 * namespace and its `use` imports (plain, aliased, grouped, `use function`, `use const`) — and
 * a name equal to a prefix or under `Prefix\` fails, whether or not anything defines it. That
 * covers imports, fully-qualified names (`new \GuzzleHttp\Client`, `#[\Vendor\Attribute]`) and
 * qualified names (`Psr7\Utils` after `use GuzzleHttp\Psr7;`). An unqualified name either goes
 * through an import, which is itself reported, or resolves into the file's own namespace.
 * Comments, docblocks and string literals are not code and do not count. Prefixes match
 * case-insensitively, as PHP resolves class names.
 *
 * ## Non-vacuous
 *
 * A missing directory, a directory with no PHP file, an empty prefix list and a blank prefix
 * all fail. So does a prefix that covers the scanned code's own namespace: it would report the
 * package itself.
 *
 * This is the assertion behind {@see ArchPresets::noVendorNamespace()}.
 */
final class VendorNamespaces
{
    private const string OPENS_NAMESPACE = 'namespace';

    private const string OPENS_CLASS = 'class';

    private const string OPENS_OTHER = 'other';

    /**
     * @param  list<string>  $prefixes  namespace prefixes nothing under `$srcDir` may name
     * @param  list<string>  $ignoring  class/namespace exemptions — pinned by {@see ArchExemptions}
     */
    public static function assert(array $prefixes, string $srcDir, array $ignoring = []): void
    {
        $prefixes = self::normalize($prefixes);

        Assert::assertDirectoryExists($srcDir, "Source directory does not exist: {$srcDir}");

        $files = PhpFiles::in($srcDir);

        Assert::assertNotSame(
            [],
            $files,
            "No PHP file under {$srcDir}, so the namespace ban would pass over nothing. Point it at the "
            .'directory that holds the code.',
        );

        $offenders = [];
        $ownNamespaces = [];
        $declared = [];

        foreach ($files as $file) {
            $scan = self::scan((string) file_get_contents($file));
            $where = self::relative($srcDir, $file);

            foreach ($scan['namespaces'] as $namespace) {
                $ownNamespaces[$namespace] = $where;
            }

            if ($scan['class'] !== null) {
                $declared[] = $scan['class'];
            }

            if (self::isExempt($scan['class'], $ignoring)) {
                continue;
            }

            foreach ($scan['names'] as [$name, $line]) {
                if (self::bannedBy($name, $prefixes) !== null) {
                    $offenders["{$where}:{$line} {$name}"] = true;
                }
            }
        }

        self::assertPrefixesAreForeign($prefixes, $ownNamespaces);
        self::assertExemptionsLandInScope($ignoring, $declared, $srcDir);

        $offenders = array_keys($offenders);
        sort($offenders, SORT_NATURAL);

        Assert::assertSame(
            [],
            $offenders,
            'These files reference a banned namespace ('.implode(', ', array_map(
                static fn (string $prefix): string => $prefix.'\\',
                $prefixes,
            ))."):\n  - ".implode("\n  - ", $offenders)."\nRemove the dependency, or reach it through the API the "
            .'package is allowed to depend on.',
        );
    }

    /**
     * @param  list<string>  $prefixes
     * @return non-empty-list<string>
     */
    private static function normalize(array $prefixes): array
    {
        $normalized = [];

        foreach ($prefixes as $prefix) {
            $trimmed = trim($prefix, "\\ \t\n\r\0\x0B");

            if ($trimmed === '') {
                throw new InvalidArgumentException(
                    "noVendorNamespace() got a blank prefix ('{$prefix}'). Name the namespace to ban, e.g. 'GuzzleHttp'.",
                );
            }

            $normalized[] = $trimmed;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'noVendorNamespace() needs at least one namespace prefix: an empty list bans nothing.',
            );
        }

        return array_values(array_unique($normalized));
    }

    /**
     * The prefix a name falls under (equal to it, or below `Prefix\`), or null.
     *
     * @param  list<string>  $prefixes
     */
    private static function bannedBy(string $name, array $prefixes): ?string
    {
        $lower = strtolower($name);

        foreach ($prefixes as $prefix) {
            $prefixLower = strtolower($prefix);

            if ($lower === $prefixLower || str_starts_with($lower, $prefixLower.'\\')) {
                return $prefix;
            }
        }

        return null;
    }

    /**
     * A prefix covering a namespace the scanned code declares would report the code itself —
     * every qualified name relative to that namespace resolves under it.
     *
     * @param  list<string>  $prefixes
     * @param  array<string, string>  $ownNamespaces  namespace => a file declaring it
     */
    private static function assertPrefixesAreForeign(array $prefixes, array $ownNamespaces): void
    {
        $covering = [];

        foreach ($ownNamespaces as $namespace => $file) {
            $prefix = self::bannedBy($namespace, $prefixes);

            if ($prefix !== null) {
                $covering[] = "{$prefix}\\ covers {$namespace} ({$file})";
            }
        }

        Assert::assertSame(
            [],
            $covering,
            'A banned prefix covers the scanned code\'s own namespace, so the ban would report the package '
            .'itself: '.implode(', ', $covering).'. Ban the foreign namespaces only.',
        );
    }

    /**
     * An exemption that matches no class declared under `$srcDir` exempts nothing here.
     *
     * @param  list<string>  $ignoring
     * @param  list<string>  $declared
     */
    private static function assertExemptionsLandInScope(array $ignoring, array $declared, string $srcDir): void
    {
        $outside = [];

        foreach ($ignoring as $exemption) {
            $matched = array_filter($declared, static fn (string $class): bool => self::matches($class, $exemption));

            if ($matched === []) {
                $outside[] = $exemption;
            }
        }

        Assert::assertSame(
            [],
            $outside,
            'These noVendorNamespace exemptions match no class declared under '.$srcDir.': '
            .implode(', ', $outside).'. They exempt nothing there — remove them, or pass the directory '
            .'that holds the class as $srcDir.',
        );
    }

    /**
     * @param  list<string>  $ignoring
     */
    private static function isExempt(?string $class, array $ignoring): bool
    {
        if ($class === null) {
            return false;
        }

        foreach ($ignoring as $exemption) {
            if (self::matches($class, $exemption)) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $class, string $exemption): bool
    {
        $name = ltrim($exemption, '\\');

        return $class === $name || str_starts_with($class, $name.'\\');
    }

    /**
     * Every namespace the source declares, its first declared class-like, and every name it
     * references in code — resolved, with the line it is on.
     *
     * @return array{namespaces: list<string>, class: string|null, names: list<array{0: string, 1: int}>}
     */
    private static function scan(string $source): array
    {
        $tokens = self::meaningfulTokens($source);
        $count = count($tokens);

        $namespace = '';
        $namespaces = [];
        $class = null;
        $imports = [];
        $names = [];
        $braces = [];
        $opens = self::OPENS_OTHER;

        for ($i = 0; $i < $count; $i++) {
            [$id, $text, $line] = $tokens[$i];
            $previous = $tokens[$i - 1] ?? null;

            if ($id === T_NAMESPACE) {
                // `namespace Foo;` / `namespace Foo {` — or `namespace {`, the global one.
                $next = $tokens[$i + 1] ?? [null, '', 0];
                $named = in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true);

                $namespace = $named ? $next[1] : '';
                $i += $named ? 1 : 0;
                $imports = [];
                $opens = self::OPENS_NAMESPACE;

                if ($named) {
                    $namespaces[] = $namespace;
                }

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true) && ($previous[0] ?? null) !== T_DOUBLE_COLON) {
                $next = $tokens[$i + 1] ?? [null, '', 0];

                if ($next[0] === T_STRING) {
                    $class ??= $namespace === '' ? $next[1] : $namespace.'\\'.$next[1];
                    $opens = self::OPENS_CLASS;
                    $i++;
                } elseif ($id === T_CLASS && ($previous[0] ?? null) === T_NEW) {
                    $opens = self::OPENS_CLASS;
                }

                continue;
            }

            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braces[] = $text === '{' ? $opens : self::OPENS_OTHER;
                $opens = self::OPENS_OTHER;

                continue;
            }

            if ($text === '}') {
                array_pop($braces);

                continue;
            }

            // `function () use ($x)` follows a `)`; an import never does. A `use` in a class body
            // is a trait use, whose names are ordinary references read below.
            if ($id === T_USE && ($previous[1] ?? '') !== ')' && self::isImport($braces)) {
                $i = self::readImports($tokens, $i + 1, $imports, $names);

                continue;
            }

            $name = match ($id) {
                T_NAME_FULLY_QUALIFIED => ltrim($text, '\\'),
                T_NAME_QUALIFIED => self::resolve($text, $namespace, $imports),
                default => null,
            };

            if ($name !== null) {
                $names[] = [$name, $line];
            }
        }

        return ['namespaces' => $namespaces, 'class' => $class, 'names' => $names];
    }

    /**
     * A `use` is an import only outside every class and function body.
     *
     * @param  list<string>  $braces
     */
    private static function isImport(array $braces): bool
    {
        foreach ($braces as $opened) {
            if ($opened !== self::OPENS_NAMESPACE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read one `use` statement starting at $i (just past `use`): record every imported name —
     * classes, functions and constants alike — and register the class aliases. Returns the index
     * of the terminating `;`.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     * @param  array<string, string>  $imports  lower-case class alias => imported name
     * @param  list<array{0: string, 1: int}>  $names
     */
    private static function readImports(array $tokens, int $i, array &$imports, array &$names): int
    {
        $count = count($tokens);
        $classes = ! in_array($tokens[$i][0] ?? null, [T_FUNCTION, T_CONST], true);
        $prefix = '';
        $group = null;

        while ($i < $count && $tokens[$i][1] !== ';') {
            [$id, $text, $line] = $tokens[$i];

            if ($text === '{') {
                // Group use: every entry up to `}` is relative to the name read before `\{`.
                $prefix = $group ?? '';
                $group = null;
            } elseif ($text === '}') {
                $prefix = '';
            } elseif (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($text, '\\');
                $next = $tokens[$i + 1] ?? [null, '', 0];

                if ($next[0] === T_NS_SEPARATOR) {
                    // `use A\B\{…}` — the group prefix; the `{` that follows applies it.
                    $group = $name;
                    $i += 2;

                    continue;
                }

                $imported = $prefix === '' ? $name : $prefix.'\\'.$name;
                $names[] = [$imported, $line];

                // `use A\{function b, C}`: only class entries alias a class name.
                $entryIsClass = $classes && ! in_array($tokens[$i - 1][0] ?? null, [T_FUNCTION, T_CONST], true);
                $alias = self::shortName($imported);

                if ($next[0] === T_AS && ($tokens[$i + 2][0] ?? null) === T_STRING) {
                    $alias = $tokens[$i + 2][1];
                    $i += 2;
                }

                if ($entryIsClass) {
                    $imports[strtolower($alias)] = $imported;
                }
            }

            $i++;
        }

        return $i;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function resolve(string $name, string $namespace, array $imports): string
    {
        // A qualified name always has a `\`: its first segment may be an imported alias.
        [$first, $rest] = array_pad(explode('\\', $name, 2), 2, '');
        $alias = strtolower($first);

        if (isset($imports[$alias])) {
            return $imports[$alias].'\\'.$rest;
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    private static function shortName(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }

    private static function relative(string $srcDir, string $file): string
    {
        return ltrim(str_replace(rtrim($srcDir, '/'), '', $file), '/');
    }

    /**
     * Tokenize and drop whitespace, comments, docblocks and inline HTML — only code resolves.
     *
     * @return list<array{0: int|null, 1: string, 2: int}>
     */
    private static function meaningfulTokens(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];

                if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    $tokens[] = [$token[0], $token[1], $token[2]];
                }

                continue;
            }

            $tokens[] = [null, $token, $line];
        }

        return $tokens;
    }
}
