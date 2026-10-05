<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The debugging-leftover ban, scanned from source tokens instead of resolved symbols.
 *
 * ## Why this is not a Pest arch expectation
 *
 * It used to be: `expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])->not->toBeUsed()`.
 * Four of those five bite. **`ray` never has, and never could.**
 *
 * The arch layer builds each file's dependency list by parsing it and then *filtering every
 * parsed name through whether the symbol exists* — `phpunit-architecture-test`'s
 * `ObjectDependenciesDescription` keeps a name only when `function_exists()`, `class_exists()`,
 * `interface_exists()`, `enum_exists()` or `trait_exists()` says yes, and defaults to `false`.
 * The `ray()` debugger package is not in our dependency graph and never will be — the Dependency Policy
 * forbids it — so `ray` is not a defined function, so it is filtered out of every dependency
 * list, so the ban has nothing to compare against and passes. Silently. Measured: a file in
 * `src/` calling `ray()`, `dd()` and `var_dump()` was reported for `dd` and `var_dump` and
 * never for `ray`.
 *
 * That made the one debug tool a developer would realistically leave behind the exact one the
 * preset could not catch — and a `ray()` left in shipped code is not a cosmetic problem but a
 * fatal `Call to undefined function`, precisely *because* the package is not installed.
 *
 * Tokens have no such gate: a call is a call whether or not anything defines it. So the scan
 * is the fix, and it is uniform — every banned function is found the same way, and the ban
 * can fail on all five.
 *
 * Comments and docblocks are dropped before matching, so prose mentioning `dd()` is invisible
 * here; only real code counts.
 *
 * This is the assertion behind {@see ArchPresets::noDebuggingLeftovers()}.
 */
final class DebugLeftovers
{
    /**
     * The banned debugging functions — the same five the arch expectation named, no more.
     * Widening the ban here would be a separate decision affecting 19 packages, and this
     * change is about making the existing five all *capable of failing*. Public so a
     * consumer can reuse the exact list.
     *
     * @var list<string>
     */
    public const array FUNCTIONS = ['dd', 'dump', 'ray', 'var_dump', 'print_r'];

    /**
     * The debugging helpers Laravel hangs on its own objects — `$query->dd()`,
     * `$collection->dd()`, `->ddRawSql()`, `->dumpRawSql()` — banned when reached through `->`.
     * As fatal in production as the global `dd()`, and they were invisible because every `->`
     * call was skipped. `->dump()` is deliberately NOT here: `$yaml->dump()`,
     * `$exporter->dump()` and a package's own `Resource::dump()` are ordinary API, so banning
     * the name would fail correct code; the global `dump()` stays banned above.
     *
     * @var list<string>
     */
    public const array METHODS = ['dd', 'ddRawSql', 'dumpRawSql'];

    /**
     * @param  list<string>  $ignoring  class/namespace exemptions — pinned by {@see ArchExemptions}
     */
    public static function assert(string $srcDir, array $ignoring = []): void
    {
        Assert::assertDirectoryExists($srcDir, "Source directory does not exist: {$srcDir}");

        $leftovers = [];
        $declared = [];

        foreach (self::phpFiles($srcDir) as $file) {
            $tokens = self::meaningfulTokens((string) file_get_contents($file));
            $class = self::declaredClass($tokens);

            if ($class !== null) {
                $declared[] = $class;
            }

            if (self::isExempt($class, $ignoring)) {
                continue;
            }

            foreach (self::calledDebugFunctions($tokens) as $function) {
                $leftovers[] = self::relative($srcDir, $file).": {$function}()";
            }
        }

        self::assertExemptionsLandInScope($ignoring, $declared, $srcDir);

        sort($leftovers);

        Assert::assertSame(
            [],
            $leftovers,
            'These files call a debugging function that must not ship: '.implode(', ', $leftovers)
            .'. Remove the call. (A left-behind ray() is a fatal Call to undefined function, not a '
            .'cosmetic problem — the ray() debugger package is deliberately not in the dependency graph.)',
        );
    }

    /**
     * An exemption that matches no class declared under `$srcDir` exempts nothing here —
     * it names a real class somewhere else, or a namespace this directory does not hold.
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
            'These noDebuggingLeftovers exemptions match no class declared under '.$srcDir.': '
            .implode(', ', $outside).'. They exempt nothing there — remove them, or pass the directory '
            .'that holds the class as $srcDir.',
        );
    }

    /**
     * Every banned function actually **called** in the file, and every banned helper
     * **method** called through `->` / `?->` (reported as `->dd`).
     *
     * A function call is `name(` where `name` is not reached through `->` or `::` (a method
     * named `dump()` on some object is not this ban's business), is not the
     * `function dump()` declaration itself and is not a `new Dump(` instantiation. `\dd(`
     * arrives as one fully-qualified token and is matched too. A method call is `->name(` for
     * a name in {@see self::METHODS}. Names match case-insensitively, as PHP calls them:
     * `DD($x)` runs `dd()`.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<string>
     */
    private static function calledDebugFunctions(array $tokens): array
    {
        $found = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if (! in_array($id, [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $name = ltrim($text, '\\');
            $lower = strtolower($name);

            if (! in_array($lower, self::lowercased(self::FUNCTIONS), true) && ! in_array($lower, self::lowercased(self::METHODS), true)) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;

            if ($next === null || $next[1] !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            if ($previous !== null && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                if ($id === T_STRING && in_array($lower, self::lowercased(self::METHODS), true)) {
                    $found[] = '->'.$text;
                }

                continue;
            }

            // `Foo::dump(`, `function dump(`, `new Dump(` — not a call to the global.
            if ($previous !== null && in_array($previous[0], [T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }

            $found[] = $name;
        }

        return array_values(array_unique($found));
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private static function lowercased(array $names): array
    {
        return array_map(strtolower(...), $names);
    }

    /**
     * Whether the file's declared class is covered by an exemption — matched as an exact
     * class name or as a namespace prefix, which is exactly what Pest's `->ignoring()`
     * accepted. Exemptions themselves are pinned by {@see ArchExemptions}, so a typo fails
     * rather than silently widening the ban's blind spot.
     *
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
     * The fully-qualified name of the first class-like declared in the file — a class, trait,
     * enum or interface, each of which an exemption may name — or null when it declares none.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function declaredClass(array $tokens): ?string
    {
        $namespace = null;
        $count = count($tokens);

        foreach ($tokens as $i => [$id]) {
            if ($id === T_NAMESPACE) {
                $token = $tokens[$i + 1] ?? null;
                $namespace = $token !== null && in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true)
                    ? $token[1]
                    : null;

                break;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            if (! in_array($tokens[$i][0], [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true)) {
                continue;
            }

            // `Foo::class` is the constant; `new class` is anonymous.
            $previous = $tokens[$i - 1] ?? null;

            if ($previous !== null && ($previous[1] === '::' || $previous[0] === T_NEW)) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if ($name === null || $name[0] !== T_STRING) {
                continue;
            }

            return $namespace === null ? $name[1] : $namespace.'\\'.$name[1];
        }

        return null;
    }

    private static function relative(string $srcDir, string $file): string
    {
        return ltrim(str_replace(rtrim($srcDir, '/'), '', $file), '/');
    }

    /**
     * Tokenize and drop comments/docblocks/whitespace, so a docblock mentioning `dd()` —
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
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
