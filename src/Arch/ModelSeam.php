<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The permissions #34 pin, generalized: a package with a config-swappable model must
 * resolve that model **through its seam** and nowhere else.
 *
 * Two ways the seam gets bypassed, both caught here from source tokens:
 *
 *   1. `static::query()` / `self::query()` / `new static` **inside an Eloquent model**.
 *      Late static binding resolves to the class the code *named*, not the one the host
 *      *configured* — so a "findOrCreate" helper silently creates and queries rows as
 *      the packaged class, and authorization for a host's own subclass reads empty. The
 *      correct seam resolves the configured class-string first (`self::class()::query()`)
 *      and is therefore never flagged.
 *
 *      This check is gated on the class actually extending {@see Model}, and that gate is
 *      load-bearing rather than decorative. `self::query()` is only a seam bypass if
 *      `query()` is Eloquent's — in a plain class it is a call to that class's own static
 *      helper, and `new static` is the ordinary named-constructor idiom. Ungated, this
 *      flagged two packages' non-model support classes for correct code. The preset is an
 *      `it()` case with no `->ignoring()` escape, so a false positive here is
 *      unappealable — the gate is what keeps the ban honest.
 *
 *   2. the `'<pkg>.<model-key>'` config literal appearing in a file outside the seam
 *      directory. Only the seam may read the swap key; a stray literal anywhere else is
 *      a second, competing resolution path that will drift from the seam.
 *
 * This is the assertion behind {@see ArchPresets::modelsResolveThroughSeam()}.
 */
final class ModelSeam
{
    /**
     * A config key that swaps a model: a dotted key whose final segment is `model`,
     * `models`, or ends in `_model` (e.g. `shops.shop_model`, `passkeys.model`,
     * `permissions.models.permission`). Tight enough not to trip on arbitrary strings.
     */
    private const MODEL_KEY = '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/';

    public static function assert(string $srcDir, string $seamDir = 'Support'): void
    {
        Assert::assertDirectoryExists($srcDir, "Source directory does not exist: {$srcDir}");

        $lateBinding = [];
        $strayLiterals = [];

        // Tokenize once, up front: deciding whether a file is a model means resolving its
        // parent chain, and that chain can run through the other files in the set.
        $tokenized = [];

        foreach (self::phpFiles($srcDir) as $file) {
            $tokenized[$file] = self::meaningfulTokens((string) file_get_contents($file));
        }

        $parents = self::parentChain($tokenized);

        foreach ($tokenized as $file => $tokens) {
            if (self::isModel($tokens, $parents) && self::usesLateStaticResolution($tokens)) {
                $lateBinding[] = self::relative($srcDir, $file);
            }

            if (! self::isInSeam($srcDir, $file, $seamDir)) {
                foreach (self::modelKeyLiterals($tokens) as $literal) {
                    $strayLiterals[] = self::relative($srcDir, $file).": '{$literal}'";
                }
            }
        }

        sort($lateBinding);
        sort($strayLiterals);

        Assert::assertSame(
            [],
            $lateBinding,
            'These files resolve a model through late static binding (static::query() / self::query() / '
            .'new static) instead of the configured seam, so a host subclass swap is ignored: '
            .implode(', ', $lateBinding).'. Resolve the configured class-string first.',
        );

        Assert::assertSame(
            [],
            $strayLiterals,
            "These files read a swappable-model config literal outside the '{$seamDir}' seam, creating a "
            .'competing resolution path: '.implode(', ', $strayLiterals)
            .". Read the key only through the {$seamDir} seam.",
        );
    }

    /**
     * Whether the file declares an Eloquent model.
     *
     * Resolved from the `extends` chain, not from a single `extends Model` match: a model
     * that reaches {@see Model} through an intermediate package base (`class Post extends
     * PackageModel`) is still a model, and missing it would hand the ban a silent false
     * negative. $parents carries the chain across the whole scanned set; anything that
     * leaves the set (a base class from another package) is resolved by reflection.
     *
     * A class whose chain can be resolved neither way is not flagged — the check errs
     * toward the false negative, never the unappealable false positive.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $parents
     */
    private static function isModel(array $tokens, array $parents): bool
    {
        $class = self::declaredClass($tokens);

        if ($class === null) {
            return false;
        }

        $seen = [];

        while (isset($parents[$class]) && ! isset($seen[$class])) {
            $seen[$class] = true;
            $class = $parents[$class];

            if ($class === Model::class) {
                return true;
            }
        }

        // The chain left the scanned set. Reflection covers a base class that lives in
        // another package (or in the framework) without needing it in $parents.
        return class_exists($class) && is_subclass_of($class, Model::class);
    }

    /**
     * Map every class declared in the scanned set to its resolved parent class.
     *
     * @param  array<string, list<array{0: int|null, 1: string}>>  $tokenized
     * @return array<string, string>
     */
    private static function parentChain(array $tokenized): array
    {
        $parents = [];

        foreach ($tokenized as $tokens) {
            $class = self::declaredClass($tokens);
            $parent = self::declaredParent($tokens);

            if ($class !== null && $parent !== null) {
                $parents[$class] = $parent;
            }
        }

        return $parents;
    }

    /**
     * The fully-qualified name of the first class declared in the file, or null when the
     * file declares no named class (an interface, a trait, an enum, a plain function file).
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function declaredClass(array $tokens): ?string
    {
        $index = self::classDeclarationIndex($tokens);

        if ($index === null) {
            return null;
        }

        $namespace = self::declaredNamespace($tokens);
        $name = $tokens[$index + 1][1];

        return $namespace === null ? $name : $namespace.'\\'.$name;
    }

    /**
     * The fully-qualified name of the class the file's class extends, or null when it
     * extends nothing. Resolved against the file's `use` imports and namespace, so
     * `extends Model`, `extends EloquentModel` (aliased) and `extends \Vendor\Base` all
     * land on the same answer.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function declaredParent(array $tokens): ?string
    {
        $index = self::classDeclarationIndex($tokens);

        if ($index === null) {
            return null;
        }

        $extends = $tokens[$index + 2] ?? null;
        $parent = $tokens[$index + 3] ?? null;

        if ($extends === null || $extends[0] !== T_EXTENDS || $parent === null) {
            return null;
        }

        return self::resolveName($parent[1], self::imports($tokens), self::declaredNamespace($tokens));
    }

    /**
     * Resolve a name as written in source to a fully-qualified class name.
     *
     * @param  array<string, string>  $imports  alias (lowercased) => fully-qualified name
     */
    private static function resolveName(string $name, array $imports, ?string $namespace): string
    {
        // Already fully qualified.
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $alias = strtolower($segments[0]);

        if (isset($imports[$alias])) {
            $segments[0] = $imports[$alias];

            return implode('\\', $segments);
        }

        return $namespace === null ? $name : $namespace.'\\'.$name;
    }

    /**
     * The file's top-level `use` imports, keyed by lowercased alias.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return array<string, string>
     */
    private static function imports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);
        $stop = self::classDeclarationIndex($tokens) ?? $count;

        for ($i = 0; $i < $stop; $i++) {
            if ($tokens[$i][0] !== T_USE) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            // `use function foo;` / `use const BAR;` import no class.
            if ($name === null || ! in_array($name[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $segments = explode('\\', $name[1]);
            $alias = end($segments);

            // `use Vendor\Base as PackageModel;`
            if (($tokens[$i + 2][0] ?? null) === T_AS && isset($tokens[$i + 3])) {
                $alias = $tokens[$i + 3][1];
            }

            $imports[strtolower($alias)] = $name[1];
        }

        return $imports;
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function declaredNamespace(array $tokens): ?string
    {
        foreach ($tokens as $i => [$id]) {
            if ($id === T_NAMESPACE) {
                return self::readName($tokens, $i + 1);
            }
        }

        return null;
    }

    /**
     * The index of the `class` keyword that declares the file's class, or null when there
     * is none.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function classDeclarationIndex(array $tokens): ?int
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` is the constant, not a declaration; `new class` is anonymous
            // and has no name to resolve.
            $previous = $tokens[$i - 1] ?? null;

            if ($previous !== null && ($previous[1] === '::' || $previous[0] === T_NEW)) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if ($name === null || $name[0] !== T_STRING) {
                continue;
            }

            return $i;
        }

        return null;
    }

    /**
     * Read a (possibly qualified) name starting at $offset. PHP 8 hands back a whole
     * `A\B\C` as one T_NAME_QUALIFIED token, but a single-segment name is still T_STRING.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function readName(array $tokens, int $offset): ?string
    {
        $token = $tokens[$offset] ?? null;

        if ($token === null) {
            return null;
        }

        return in_array($token[0], [T_STRING, T_NAME_QUALIFIED], true) ? $token[1] : null;
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function usesLateStaticResolution(array $tokens): bool
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            // `new static`
            if ($id === T_NEW) {
                $next = $tokens[$i + 1] ?? null;

                if ($next !== null && $next[0] === T_STATIC) {
                    return true;
                }
            }

            // `static::query(` or `self::query(`
            if ($text === '::') {
                $prev = $tokens[$i - 1] ?? null;
                $method = $tokens[$i + 1] ?? null;
                $paren = $tokens[$i + 2] ?? null;

                $onStaticOrSelf = $prev !== null
                    && ($prev[0] === T_STATIC || ($prev[0] === T_STRING && $prev[1] === 'self'));

                if ($onStaticOrSelf
                    && $method !== null && $method[0] === T_STRING && $method[1] === 'query'
                    && $paren !== null && $paren[1] === '(') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<string>
     */
    private static function modelKeyLiterals(array $tokens): array
    {
        $literals = [];

        foreach ($tokens as [$id, $text]) {
            if ($id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = self::stringValue($text);

            if (preg_match(self::MODEL_KEY, $value) === 1 && self::hasModelSegment($value)) {
                $literals[] = $value;
            }
        }

        return array_values(array_unique($literals));
    }

    private static function hasModelSegment(string $value): bool
    {
        foreach (explode('.', $value) as $segment) {
            if ($segment === 'model' || $segment === 'models' || str_ends_with($segment, '_model')) {
                return true;
            }
        }

        return false;
    }

    private static function isInSeam(string $srcDir, string $file, string $seamDir): bool
    {
        $relative = self::relative($srcDir, $file);

        return str_starts_with($relative, $seamDir.'/');
    }

    private static function relative(string $srcDir, string $file): string
    {
        return ltrim(str_replace(rtrim($srcDir, '/'), '', $file), '/');
    }

    /**
     * Tokenize and drop comments/docblocks/whitespace so a mention of `static::query()`
     * or a config key in prose never trips the guard — only real code does.
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

    private static function stringValue(string $raw): string
    {
        $quote = $raw[0] ?? '';
        $inner = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
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
