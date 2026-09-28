<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

/**
 * The fully-qualified class names a PHP source file **references in code**, resolved the way
 * PHP resolves them: `use` imports (plain, aliased and grouped), fully-qualified names,
 * qualified names and same-namespace short names.
 *
 * Comments and docblocks are dropped before resolution, so an `@see CreateTeam` or a
 * commented-out call never counts as a reference — a facade that only *mentions* an action in
 * prose does not reach it. String literals are not resolved either: `app('Vendor\Action')` is
 * not a reference, which errs toward reporting a real reference as missing (a loud red that a
 * `::class` fixes) rather than inventing one (a silent green).
 *
 * Every bare identifier that is not a member access or a declaration name is resolved as if it
 * were a class, so the result also holds junk names (functions, constants) resolved into the
 * current namespace. That is harmless by construction: callers only ever intersect the result
 * with a set of real class names.
 */
final class SourceReferences
{
    private const string OPENS_NAMESPACE = 'namespace';

    private const string OPENS_CLASS = 'class';

    private const string OPENS_OTHER = 'other';

    /**
     * Tokens after which an identifier names a member or a declaration, never a class.
     */
    private const array NOT_A_CLASS_AFTER = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_CONST,
        T_GOTO,
    ];

    /**
     * @return list<string>
     */
    public static function inFile(string $file, bool $withImports = false): array
    {
        return self::inSource((string) file_get_contents($file), $withImports);
    }

    /**
     * @param  bool  $withImports  also count the targets of `use` imports themselves — an
     *                             import is a dependency even when the name is never used
     * @return list<string>
     */
    public static function inSource(string $source, bool $withImports = false): array
    {
        $tokens = self::meaningfulTokens($source);
        $count = count($tokens);

        $namespace = '';
        $imports = [];
        $references = [];
        $braces = [];
        $opens = self::OPENS_OTHER;

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            $previous = $tokens[$i - 1][0] ?? null;

            if ($id === T_NAMESPACE) {
                // `namespace Foo;` / `namespace Foo {` — or `namespace {`, the global one.
                $next = $tokens[$i + 1] ?? [null, ''];
                $named = in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true);

                $namespace = $named ? $next[1] : '';
                $i += $named ? 1 : 0;
                $imports = [];
                $opens = self::OPENS_NAMESPACE;

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                $next = $tokens[$i + 1] ?? [null, ''];

                if ($next[0] === T_STRING) {
                    $opens = self::OPENS_CLASS;
                    $i++;
                } elseif ($id === T_CLASS && $previous === T_NEW) {
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

            // `function () use ($x)` follows a `)`; an import never does.
            if ($id === T_USE && ($tokens[$i - 1][1] ?? '') !== ')' && self::isImport($braces)) {
                $i = self::readImports($tokens, $i + 1, $imports, $references, $withImports);

                continue;
            }

            $name = match ($id) {
                T_NAME_FULLY_QUALIFIED => ltrim($text, '\\'),
                T_NAME_RELATIVE => self::qualify($namespace, substr($text, strlen('namespace\\'))),
                T_NAME_QUALIFIED => self::resolve($text, $namespace, $imports),
                T_STRING => in_array($previous, self::NOT_A_CLASS_AFTER, true)
                    ? null
                    : self::resolve($text, $namespace, $imports),
                default => null,
            };

            if ($name !== null) {
                $references[$name] = true;
            }
        }

        return array_keys($references);
    }

    /**
     * A `use` is an import only outside every class and function body — inside a class body
     * it is a trait use (whose names are ordinary references) and inside a function it is a
     * closure's variable list.
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
     * Read one `use` statement starting at $i (just past `use`), registering every imported
     * alias. Returns the index of the terminating `;`.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports  lower-case alias => FQCN
     * @param  array<string, true>  $references
     */
    private static function readImports(array $tokens, int $i, array &$imports, array &$references, bool $withImports): int
    {
        $count = count($tokens);

        // `use function …;` / `use const …;` import no class.
        if (in_array($tokens[$i][0] ?? null, [T_FUNCTION, T_CONST], true)) {
            while ($i < $count && $tokens[$i][1] !== ';') {
                $i++;
            }

            return $i;
        }

        $prefix = '';
        $group = null;

        while ($i < $count && $tokens[$i][1] !== ';') {
            [$id, $text] = $tokens[$i];

            if ($text === '{') {
                // Group use: every entry up to `}` is relative to the name read before `\{`.
                $prefix = $group ?? '';
                $group = null;
                $i++;

                continue;
            }

            if ($text === '}') {
                $prefix = '';
                $i++;

                continue;
            }

            if ($id === T_FUNCTION || $id === T_CONST) {
                // `use A\{function b}` — skip the entry through its separator.
                while ($i < $count && ! in_array($tokens[$i][1], [',', '}', ';'], true)) {
                    $i++;
                }

                continue;
            }

            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($text, '\\');
                $next = $tokens[$i + 1] ?? [null, ''];

                if ($next[0] === T_NS_SEPARATOR) {
                    // `use A\B\{…}` — the group prefix; the `{` that follows applies it.
                    $group = $name;
                    $i += 2;

                    continue;
                }

                $fqcn = $prefix === '' ? $name : $prefix.'\\'.$name;
                $alias = self::shortName($fqcn);

                if ($next[0] === T_AS && ($tokens[$i + 2][0] ?? null) === T_STRING) {
                    $alias = $tokens[$i + 2][1];
                    $i += 2;
                }

                $imports[strtolower($alias)] = $fqcn;

                if ($withImports) {
                    $references[$fqcn] = true;
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
        $separator = strpos($name, '\\');
        $first = strtolower($separator === false ? $name : substr($name, 0, $separator));

        if (isset($imports[$first])) {
            return $imports[$first].($separator === false ? '' : substr($name, $separator));
        }

        return self::qualify($namespace, $name);
    }

    private static function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    private static function shortName(string $fqcn): string
    {
        $separator = strrpos($fqcn, '\\');

        return $separator === false ? $fqcn : substr($fqcn, $separator + 1);
    }

    /**
     * Tokenize and drop whitespace, comments and docblocks — only code resolves.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private static function meaningfulTokens(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    continue;
                }

                $tokens[] = [$token[0], $token[1]];

                continue;
            }

            $tokens[] = [null, $token];
        }

        return $tokens;
    }
}
