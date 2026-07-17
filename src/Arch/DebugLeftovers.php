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
 * `acme/ray` is not in our dependency graph and never will be — the Dependency Policy
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
     * @param  list<string>  $ignoring  class/namespace exemptions — pinned by {@see ArchExemptions}
     */
    public static function assert(string $srcDir, array $ignoring = []): void
    {
        Assert::assertDirectoryExists($srcDir, "Source directory does not exist: {$srcDir}");

        $leftovers = [];

        foreach (self::phpFiles($srcDir) as $file) {
            $tokens = self::meaningfulTokens((string) file_get_contents($file));

            if (self::isExempt($tokens, $ignoring)) {
                continue;
            }

            foreach (self::calledDebugFunctions($tokens) as $function) {
                $leftovers[] = self::relative($srcDir, $file).": {$function}()";
            }
        }

        sort($leftovers);

        Assert::assertSame(
            [],
            $leftovers,
            'These files call a debugging function that must not ship: '.implode(', ', $leftovers)
            .'. Remove the call. (A left-behind ray() is a fatal Call to undefined function, not a '
            .'cosmetic problem — acme/ray is deliberately not in the dependency graph.)',
        );
    }

    /**
     * Every banned function actually **called** in the file.
     *
     * A call is `name(` where `name` is not reached through `->` or `::` (a method named
     * `dump()` on some object is not this ban's business) and is not the `function dump()`
     * declaration itself. `\dd(` arrives as one fully-qualified token and is matched too.
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

            if (! in_array($name, self::FUNCTIONS, true)) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;

            if ($next === null || $next[1] !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            // `$x->dump(`, `Foo::dump(`, `function dump(` — not a call to the global.
            if ($previous !== null && in_array($previous[0], [
                T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION,
            ], true)) {
                continue;
            }

            $found[] = $name;
        }

        return array_values(array_unique($found));
    }

    /**
     * Whether the file's declared class is covered by an exemption — matched as an exact
     * class name or as a namespace prefix, which is exactly what Pest's `->ignoring()`
     * accepted. Exemptions themselves are pinned by {@see ArchExemptions}, so a typo fails
     * rather than silently widening the ban's blind spot.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  list<string>  $ignoring
     */
    private static function isExempt(array $tokens, array $ignoring): bool
    {
        $class = self::declaredClass($tokens);

        if ($class === null) {
            return false;
        }

        foreach ($ignoring as $exemption) {
            $name = ltrim($exemption, '\\');

            if ($class === $name || str_starts_with($class, $name.'\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The fully-qualified name of the first class declared in the file, or null when it
     * declares none.
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
            if ($tokens[$i][0] !== T_CLASS) {
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
