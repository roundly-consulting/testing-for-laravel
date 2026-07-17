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
 * ## Declare your swap keys — key *shape* is a floor, not a guarantee
 *
 * Given no `$modelKeys`, the stray-literal half infers swap keys **by key shape** (a
 * `model` / `models` / `*_model` segment). Measured across the ten packages that adopt this
 * preset, that inference is **complete for nine** and shortchanges exactly one — but you
 * cannot tell which you are without checking, and that is the whole problem:
 *
 * `alerts` invites swapping four models. Only `alerts.history.model` has a `model` segment.
 * `alerts.silence-model` is *hyphenated* where the pattern tests for an underscore, and
 * `alerts.alert` / `alerts.health-check` carry no `model` segment at all — so a stray
 * `config('alerts.alert')` left the preset **green**, covering one seam of four while
 * looking authoritative. Covering 1-of-4 while looking authoritative is worse than covering
 * nothing: it invites a package to delete the bespoke rule that was doing the real work.
 *
 * **Widening the pattern cannot fix this.** In alerts' own config `alerts.silence` is a
 * **boolean** and `alerts.job` is a **job class**, and both are shape-identical to
 * `alerts.alert`, a **model**. Any pattern loose enough to catch `alerts.alert` also catches
 * those two, and this preset is an `it()` case with no `->ignoring()` escape — a false
 * positive here is unappealable.
 *
 * **Resolving the key's *value* could tell them apart** — `config('alerts.silence')` is
 * `true`, `config('alerts.alert')` is a `Model` subclass — and that is exact rather than a
 * guess. It is deliberately **not** done here, for one reason: it would make the strength of
 * an arch assertion depend on whether the arch file happens to be bound to a TestCase.
 * Pest binds a test case per directory and an arch file is not test-cased automatically (see
 * alerts' own `Pest.php`, which binds `ArchTest.php` by hand precisely so a config-reading
 * preset works). A package that forgot that binding would silently drop back to the shape
 * floor — an invisible, environment-dependent subset, which is the failure class this
 * parameter exists to end. A declaration reads the same with or without an app.
 *
 * So **declare the keys**: pass `$modelKeys` and they are policed by name, with no shape
 * convention required. This mirrors {@see SwappableModels}, where the caller already writes
 * the same keys down as `[Model::class => 'config.key']` — so the list is one preset away in
 * every adopting package's arch file. Declared keys are **unioned** with the inferred set,
 * never substituted for it, so declaring can only add coverage. They are rot-proofed the way
 * `allowUnread` and arch exemptions are: a key that appears nowhere in the source fails
 * rather than silently covering nothing.
 *
 * The residual gap, stated rather than papered over: a swap key that is both unconventionally
 * named **and** undeclared is invisible to this half. Nothing here can find it, and this
 * docblock is the only thing that will tell you so.
 *
 * This is the assertion behind {@see ArchPresets::modelsResolveThroughSeam()}.
 */
final class ModelSeam
{
    /**
     * A config key that swaps a model: a dotted key whose final segment is `model`,
     * `models`, or ends in `_model` (e.g. `shops.shop_model`, `passkeys.model`,
     * `permissions.models.permission`). Tight enough not to trip on arbitrary strings.
     *
     * Always applied, and unioned with any declared `$modelKeys` — see the class docblock
     * for why this inference is a floor, not a guarantee.
     */
    private const MODEL_KEY = '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/';

    /**
     * @param  list<string>  $modelKeys  the swap keys to police; declared beats inferred
     */
    public static function assert(string $srcDir, string $seamDir = 'Support', array $modelKeys = []): void
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

        $declaredSeen = [];

        foreach ($tokenized as $file => $tokens) {
            if (self::isModel($tokens, $parents) && self::usesLateStaticResolution($tokens)) {
                $lateBinding[] = self::relative($srcDir, $file);
            }

            // Union, never replace: declaring a key may only ever ADD coverage. Were a
            // declaration to replace the inferred set, a package that declares three of its
            // four keys would silently LOSE the fourth — the very failure this parameter
            // exists to fix, re-introduced by the fix itself. Union makes coverage monotone
            // in the declaration, so a partial list is merely partial, never a regression.
            $literals = array_values(array_unique(array_merge(
                self::modelKeyLiterals($tokens),
                self::declaredKeyLiterals($tokens, $modelKeys),
            )));

            foreach ($literals as $literal) {
                $declaredSeen[$literal] = true;

                if (! self::isInSeam($srcDir, $file, $seamDir)) {
                    $strayLiterals[] = self::relative($srcDir, $file).": '{$literal}'";
                }
            }
        }

        self::assertDeclaredKeysExist($modelKeys, $declaredSeen);

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

    /**
     * The declared swap keys this file mentions — an exact literal match, with no shape
     * inference at all. A declared key needs no naming convention, which is the entire point:
     * `alerts.alert` and `alerts.health-check` are swap keys and look like nothing special.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  list<string>  $modelKeys
     * @return list<string>
     */
    private static function declaredKeyLiterals(array $tokens, array $modelKeys): array
    {
        $literals = [];

        foreach ($tokens as [$id, $text]) {
            if ($id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = self::stringValue($text);

            if (in_array($value, $modelKeys, true)) {
                $literals[] = $value;
            }
        }

        return array_values(array_unique($literals));
    }

    /**
     * Every declared key must actually appear in the source — anywhere, seam included.
     *
     * A declared key that matches no literal polices nothing, and says nothing about it:
     * either it is a typo (`alerts.silence_model` for the hyphenated `alerts.silence-model`
     * — exactly the confusion that motivated declaring keys in the first place) or it names a
     * seam that has been removed. Both leave a hole shaped like coverage. This is the
     * rot-proofing standard the config contract already holds `allowUnread` to, and
     * {@see ArchExemptions} holds arch exemptions to.
     *
     * @param  list<string>  $modelKeys
     * @param  array<string, true>  $seen
     */
    private static function assertDeclaredKeysExist(array $modelKeys, array $seen): void
    {
        $missing = [];

        foreach ($modelKeys as $key) {
            if (! isset($seen[$key])) {
                $missing[] = $key;
            }
        }

        sort($missing);

        Assert::assertSame(
            [],
            $missing,
            'These declared model-swap keys appear nowhere in the source, so they police nothing: '
            .implode(', ', $missing).'. Either the key is a typo, or the seam it named is gone. '
            .'A declared key that matches no literal is a hole shaped like coverage.',
        );
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
