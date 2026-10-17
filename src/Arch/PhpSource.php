<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use RoundlyConsulting\Testing\Support\PhpFiles;

/**
 * One PHP file read as **code**: its meaningful tokens with their lines, its namespace, its class
 * imports and the first class-like it declares. Comments, docblocks, whitespace and inline HTML
 * are dropped before anything reads the tokens, so prose never counts — a docblock mentioning
 * `Role::findOrCreate()` is not a call. String literals stay a single token, so a call spelled
 * inside a string is not one either.
 *
 * Names resolve the way PHP resolves a class name: fully-qualified as written, otherwise through
 * the `use` imports (plain, aliased and grouped; `use function` / `use const` import no class),
 * otherwise into the file's namespace.
 *
 * @internal
 */
final readonly class PhpSource
{
    private const string OPENS_NAMESPACE = 'namespace';

    private const string OPENS_OTHER = 'other';

    /** Tokens that spell a class name in code. */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /**
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     * @param  array<string, string>  $imports  lower-case alias => fully-qualified class name
     * @param  string|null  $class  the first declared class, trait, interface or enum
     * @param  int|null  $kind  `T_CLASS`, `T_TRAIT`, `T_INTERFACE` or `T_ENUM`
     * @param  string|null  $parent  what the declared class extends, resolved
     * @param  bool  $abstract  whether the declared class is abstract
     */
    private function __construct(
        public array $tokens,
        public string $namespace,
        private array $imports,
        public ?string $class,
        public ?int $kind,
        public ?string $parent,
        public bool $abstract,
    ) {}

    /**
     * Every PHP file under `$dir`, read, keyed by path in a stable order.
     *
     * @return array<string, self>
     */
    public static function allIn(string $dir): array
    {
        $sources = [];

        foreach (PhpFiles::in($dir) as $file) {
            $sources[$file] = self::parse((string) file_get_contents($file));
        }

        return $sources;
    }

    public static function parse(string $source): self
    {
        $tokens = self::meaningfulTokens($source);
        $count = count($tokens);

        $namespace = '';
        $imports = [];
        $class = null;
        $kind = null;
        $parentName = null;
        $abstract = false;
        $braces = [];
        $opens = self::OPENS_OTHER;

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            $previous = $tokens[$i - 1] ?? null;

            if ($id === T_NAMESPACE && ($tokens[$i + 1][0] ?? null) !== T_NS_SEPARATOR) {
                $next = $tokens[$i + 1] ?? [null, '', 0];
                $named = in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true);

                $namespace = $named ? $next[1] : '';
                $i += $named ? 1 : 0;
                $imports = [];
                $opens = self::OPENS_NAMESPACE;

                continue;
            }

            if ($class === null
                && in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ($previous[0] ?? null) !== T_DOUBLE_COLON
                && ($tokens[$i + 1][0] ?? null) === T_STRING) {
                $class = $namespace === '' ? $tokens[$i + 1][1] : $namespace.'\\'.$tokens[$i + 1][1];
                $kind = $id;
                $opens = self::OPENS_OTHER;
                $abstract = self::isAbstract($tokens, $i);

                if ($id === T_CLASS && ($tokens[$i + 2][0] ?? null) === T_EXTENDS) {
                    $candidate = $tokens[$i + 3] ?? null;

                    if ($candidate !== null && in_array($candidate[0], self::NAME_TOKENS, true)) {
                        $parentName = $candidate[1];
                    }
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

            // `namespace Foo;` ends here: the next `{` opens a class or function body, not it.
            if ($text === ';') {
                $opens = self::OPENS_OTHER;
            }

            // `function () use ($x)` follows a `)`; an import never does. A `use` inside a class
            // body is a trait use, not an import.
            if ($id === T_USE && ($previous[1] ?? '') !== ')' && self::atTopLevel($braces)) {
                $i = self::readImports($tokens, $i + 1, $imports);
            }
        }

        $parent = $parentName === null ? null : self::resolveAgainst($parentName, $namespace, $imports);

        return new self($tokens, $namespace, $imports, $class, $kind, $parent, $abstract);
    }

    /**
     * Whether a token spells a class name in code (`Foo`, `A\Foo`, `\A\Foo`, `namespace\Foo`).
     */
    public static function isName(?int $id): bool
    {
        return in_array($id, self::NAME_TOKENS, true);
    }

    /**
     * Whether a token is an identifier — a method name. After `function` and `::` PHP keeps a
     * reserved word as its keyword token (`function class()` is `T_CLASS`, `X::list()` is
     * `T_LIST`), so a method name is any word token, not just `T_STRING`.
     *
     * @param  array{0: int|null, 1: string, 2: int}|null  $token
     */
    public static function isIdentifier(?array $token): bool
    {
        return $token !== null && $token[0] !== null && preg_match('/^[a-z_][a-z0-9_]*$/i', $token[1]) === 1;
    }

    /**
     * Resolve a class name as written in this file to its fully-qualified form.
     */
    public function resolve(string $name): string
    {
        return self::resolveAgainst($name, $this->namespace, $this->imports);
    }

    /**
     * Whether `$class` is, or extends, `$base` — walked through `$parents` (class => parent,
     * read from source) first, then by reflection once the chain leaves the scanned set. A chain
     * neither can resolve answers false: these checks err toward the false negative.
     *
     * @param  array<string, string>  $parents
     */
    public static function inherits(string $class, string $base, array $parents): bool
    {
        $seen = [];

        while (! isset($seen[$class])) {
            if (strcasecmp($class, $base) === 0) {
                return true;
            }

            $seen[$class] = true;

            if (! isset($parents[$class])) {
                break;
            }

            $class = $parents[$class];
        }

        return class_exists($class) && is_a($class, $base, true);
    }

    /**
     * The index of the bracket that closes the one opened at `$open` (`(`, `[` or `{`), or the
     * last index when the source is unbalanced.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     */
    public static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if ($id === null && in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === null && in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * Whether the class keyword at `$i` carries an `abstract` modifier.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     */
    private static function isAbstract(array $tokens, int $i): bool
    {
        for ($j = $i - 1; $j >= 0 && in_array($tokens[$j][0], [T_ABSTRACT, T_FINAL, T_READONLY], true); $j--) {
            if ($tokens[$j][0] === T_ABSTRACT) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function resolveAgainst(string $name, string $namespace, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return self::qualify($namespace, substr($name, strlen('namespace\\')));
        }

        [$first, $rest] = array_pad(explode('\\', $name, 2), 2, '');
        $alias = strtolower($first);

        if (isset($imports[$alias])) {
            return $rest === '' ? $imports[$alias] : $imports[$alias].'\\'.$rest;
        }

        return self::qualify($namespace, $name);
    }

    private static function qualify(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * A `use` is an import only outside every class and function body.
     *
     * @param  list<string>  $braces
     */
    private static function atTopLevel(array $braces): bool
    {
        foreach ($braces as $opened) {
            if ($opened !== self::OPENS_NAMESPACE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read one `use` statement starting just past `use`, registering its class aliases. Returns
     * the index of the terminating `;`.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function readImports(array $tokens, int $i, array &$imports): int
    {
        $count = count($tokens);
        $classes = ! in_array($tokens[$i][0] ?? null, [T_FUNCTION, T_CONST], true);
        $prefix = '';
        $group = null;

        while ($i < $count && $tokens[$i][1] !== ';') {
            [$id, $text] = $tokens[$i];

            if ($text === '{') {
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
                $isClass = $classes && ! in_array($tokens[$i - 1][0] ?? null, [T_FUNCTION, T_CONST], true);
                $separator = strrpos($imported, '\\');
                $alias = $separator === false ? $imported : substr($imported, $separator + 1);

                if ($next[0] === T_AS && ($tokens[$i + 2][0] ?? null) === T_STRING) {
                    $alias = $tokens[$i + 2][1];
                    $i += 2;
                }

                if ($isClass) {
                    $imports[strtolower($alias)] = $imported;
                }
            }

            $i++;
        }

        return $i;
    }

    /**
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
