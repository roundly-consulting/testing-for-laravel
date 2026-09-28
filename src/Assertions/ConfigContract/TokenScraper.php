<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

/**
 * Scrapes a source file for the config keys it reads, using PHP's tokenizer only —
 * never a regex over raw text.
 *
 * The distinction is load-bearing. A comment or docblock that merely *mentions*
 * `config('pkg.foo')` is a `T_COMMENT`/`T_DOC_COMMENT` token, not a
 * `T_CONSTANT_ENCAPSED_STRING`, so it is discarded here and never counts as a read.
 * A regex over the raw file (the media-library near-miss) was satisfied by exactly
 * such a comment and stayed green with the fix reverted; the tokenizer cannot be.
 *
 * What counts as a read:
 *   - a literal string argument to `config('pkg.…')` / `\config('pkg.…')` /
 *     `Config::get('pkg.…')` — the facade bare, fully-qualified, as `\Config`, or under an
 *     import alias;
 *   - a literal string argument to a read method on an **injected config repository** —
 *     `$this->config->get('pkg.…')`, `$config->string('pkg.…')` (see below);
 *   - a literal string argument to a read method on the repository reached through an
 *     **expression** — `config()->string('pkg.…')`, `app('config')->get('pkg.…')`,
 *     `app(Repository::class)->…`, `resolve('config')->…`, `->make('config')->…`,
 *     `$app['config']->…`;
 *   - every literal key of an array handed to a read method (`->get(['pkg.a' => $default])`,
 *     Laravel's `getMany`) — while an array handed to the `config()` helper is a **write**
 *     (`config(['pkg.x' => true])`) and is skipped rather than reported as unresolvable;
 *   - a `$var['key']` or `$this->prop['key']` array-offset read where the receiver is mapped
 *     to a base path via `sectionVariables` (a package that hands its whole config array to
 *     a DTO reads keys by offset, not through `config()`);
 *   - any literal string under one of `extraReadPrefixes`, wherever it appears
 *     (e.g. `ModelResolver::for('pkg.model')` — not a `config()` call).
 *
 * A `.blade.php` file is scraped through {@see BladeSource}: its echoes, directive arguments,
 * component bindings and PHP blocks are read as PHP; its markup, comments, escaped echoes and
 * `@verbatim` blocks are not. Tokenized raw, a view is one `T_INLINE_HTML` token and every read
 * in it was invisible.
 *
 * ## Driver-keyed reads
 *
 * `config("pkg.providers.{$key}.url")` — the shape of Laravel's own
 * `database.connections.<name>` — names a literal *leaf* (`url`) under a runtime *driver*.
 * The skeleton is right there in the tokens, so it is scraped into the pattern
 * `pkg.providers.*.url`, which proves the leaf without ever claiming to know the driver.
 * See {@see KeyPattern} for why that is a proof and not an assumption.
 *
 * Two shapes still resolve to no checkable pattern and are collected as interpolations, so
 * the contract fails and demands a literal key rather than guessing:
 *
 *   - a hole that does not fill a whole segment — `config("pkg.drivers.{$name}x")`;
 *   - a key not built from literals and holes at all — `config($this->keyFor('x'))`.
 *
 * A read that *stops* at the hole (`config("pkg.drivers.{$k}")`) is a wholesale section
 * read, and degrades to the literal parent `pkg.drivers`: covered forward, proving no leaf
 * in reverse — exactly how the literal `config('pkg.drivers')` has always been treated.
 *
 * ## Injected repositories
 *
 * Constructor-injecting `Illuminate\Contracts\Config\Repository` and reading through it is
 * ordinary, idiomatic Laravel — not an exotic style. It was invisible here, and the gap was
 * silent: `http-client-rate-limits` reads six keys that way, and this scraper found **zero**
 * of them in the file. Four were masked by a second, bare `config()` reader elsewhere; the
 * two that had no such reader (`cache_store`, `cache_prefix`) scraped as *unread* despite
 * being wired and tested. The tempting remedy was `allowUnread`, which asserts a falsehood
 * and blinds the reverse check on a live key.
 *
 * The binding is resolved from the **declared type**, never from the name: only a variable or
 * property whose type resolves to a config `Repository` (through the file's `use` imports, so
 * aliases work) has its `get()` counted. A `$cache->get('pkg.thing')` on a *cache* repository
 * is a different type and is not a config read — which is the distinction a name-match or a
 * bare `->get('pkg.…')` prefix-match would both get wrong, in the direction that invents a
 * read and blinds the reverse check.
 */
final class TokenScraper
{
    /**
     * The placeholder standing in for an interpolated value while a key skeleton is
     * assembled. A NUL byte cannot occur in PHP source, so it can never collide with real
     * key text — unlike `*`, which a config key could legitimately contain.
     */
    private const string HOLE = "\0";

    /**
     * The config repository types whose read methods count. Both the contract and the
     * concrete class — packages inject either.
     *
     * @var list<string>
     */
    private const array CONFIG_REPOSITORIES = [
        'Illuminate\Contracts\Config\Repository',
        'Illuminate\Config\Repository',
    ];

    /**
     * The repository methods that *read* a key by its first argument. `set()` and `push()`
     * are deliberately absent: writing a key is not evidence anything consumes it, and
     * counting a write as a read would let a key that is only ever set pass the reverse
     * check — exactly the dead-key class it exists to catch.
     *
     * @var list<string>
     */
    private const array READ_METHODS = [
        'get', 'has', 'string', 'integer', 'boolean', 'float', 'array', 'collection',
    ];

    /**
     * @param  list<string>  $extraReadPrefixes  dotted prefixes whose literals count as reads anywhere
     */
    public function __construct(
        private readonly string $prefix,
        private readonly array $extraReadPrefixes = [],
    ) {}

    /**
     * @param  array<string, string>  $sectionVariables  ['$rp' => 'pkg.rp'] — offset reads on $rp map under pkg.rp
     */
    public function scrape(string $file, array $sectionVariables = []): ScrapedFile
    {
        $source = (string) file_get_contents($file);

        if (str_ends_with(strtolower($file), '.blade.php')) {
            $source = BladeSource::toPhp($source);
        }

        $tokens = $this->meaningfulTokens($source);

        $reads = [];
        $interpolations = [];
        $dynamicSections = [];
        $prefixedReads = [];

        $imports = $this->imports($tokens);
        $repositories = $this->configRepositoryNames($tokens, $imports);

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            $helper = $this->opensConfigHelper($tokens, $i);

            if ($helper || $this->opensFacadeRead($tokens, $i, $imports) || $this->opensRepositoryRead($tokens, $i, $repositories, $imports)) {
                $argument = $this->captureFirstArgument($tokens, $i + 1);

                if ($this->isArrayArgument($argument)) {
                    // `config([...])` SETS keys — a write proves nothing is consumed. The same
                    // array handed to a read method is `getMany()`: each literal key is a read.
                    if (! $helper) {
                        foreach ($this->arrayKeys($argument) as $key) {
                            $this->classifyArgument([$key], $reads, $interpolations, $dynamicSections);
                        }
                    }

                    continue;
                }

                $this->classifyArgument($argument, $reads, $interpolations, $dynamicSections);

                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = $this->stringValue($text);

                foreach ($this->extraReadPrefixes as $extra) {
                    if (str_starts_with($value, $extra)) {
                        $reads[] = $value;
                        $prefixedReads[] = $value;
                    }
                }
            }

            if ($id === T_VARIABLE) {
                [$name, $after] = $this->sectionReceiver($tokens, $i);

                if ($name !== null && array_key_exists($name, $sectionVariables)) {
                    $offset = $this->offsetRead($tokens, $after);

                    if ($offset !== null) {
                        $reads[] = $sectionVariables[$name].'.'.$offset;
                    }
                }
            }
        }

        return new ScrapedFile(
            array_values(array_unique($reads)),
            array_values(array_unique($interpolations)),
            array_values(array_unique($dynamicSections)),
            array_values(array_unique($prefixedReads)),
        );
    }

    /**
     * Tokenize and drop everything that carries no meaning for the scrape — crucially
     * every comment and docblock, so a mention in prose is invisible here.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private function meaningfulTokens(string $source): array
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
     * True when the token at $i is the `config(` helper — bare or fully-qualified — and a real
     * call: not a `$this->config` property, a `Something::config` reference, a `function config`
     * declaration or a `new config` instantiation.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function opensConfigHelper(array $tokens, int $i): bool
    {
        if (! $this->isFunctionName($tokens[$i], 'config')) {
            return false;
        }

        $next = $tokens[$i + 1] ?? null;

        return $next !== null && $next[1] === '(' && $this->isGlobalCall($tokens, $i);
    }

    /**
     * True when the token at $i is a read method called statically on the `Config` facade:
     * `Config::get(`, `\Config::string(`, `\Illuminate\Support\Facades\Config::get(`, or an
     * import alias of the facade (`use Illuminate\Support\Facades\Config as Settings;`).
     *
     * A bare `Config` counts whatever it is imported as — the fleet's own
     * `PackageToolkit\Support\Config` helpers read keys by their first argument the same way.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     */
    private function opensFacadeRead(array $tokens, int $i, array $imports): bool
    {
        [$id, $text] = $tokens[$i];

        if ($id !== T_STRING || ! in_array($text, self::READ_METHODS, true)) {
            return false;
        }

        $next = $tokens[$i + 1] ?? null;
        $colons = $tokens[$i - 1] ?? null;
        $class = $tokens[$i - 2] ?? null;

        if ($next === null || $next[1] !== '(' || $colons === null || $colons[0] !== T_DOUBLE_COLON || $class === null
            || ! in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        return $class[1] === 'Config'
            || in_array($this->resolveName($class[1], $imports), ['Illuminate\Support\Facades\Config', 'Config'], true);
    }

    /**
     * True when the token at $i is a read method invoked on a config repository — i.e.
     * `$this->config->get(` or `$config->get(`, where the receiver's *declared type* is a
     * config `Repository`.
     *
     * Both shapes reduce to the same question: the name immediately left of `->method(` must
     * be a known repository. Like {@see self::opensConfigHelper()}, this reports the index of
     * the method name, so the caller's `$i + 1` is the opening paren either way.
     *
     * The receiver may also be an **expression** that yields the repository — see
     * {@see self::yieldsConfigRepository()}.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  list<string>  $repositories  names (no leading `$`) bound to a config repository
     * @param  array<string, string>  $imports
     */
    private function opensRepositoryRead(array $tokens, int $i, array $repositories, array $imports): bool
    {
        [$id, $text] = $tokens[$i];

        if ($id !== T_STRING || ! in_array($text, self::READ_METHODS, true)) {
            return false;
        }

        $next = $tokens[$i + 1] ?? null;

        if ($next === null || $next[1] !== '(') {
            return false;
        }

        $arrow = $tokens[$i - 1] ?? null;

        if ($arrow === null || ! in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return false;
        }

        $receiver = $tokens[$i - 2] ?? null;

        if ($receiver === null) {
            return false;
        }

        // `config()->get(`, `app('config')->get(`, `$app['config']->get(` — an expression.
        if (in_array($receiver[1], [')', ']'], true)) {
            return $this->yieldsConfigRepository($tokens, $i - 2, $imports);
        }

        // `$config->get(` — a local variable or a promoted parameter inside its constructor.
        if ($receiver[0] === T_VARIABLE) {
            return in_array(ltrim($receiver[1], '$'), $repositories, true);
        }

        // `$this->config->get(` — a property. Anchored on `$this` so an unrelated
        // `$other->config->get()` is not silently attributed to this class's property.
        if ($receiver[0] === T_STRING && in_array($receiver[1], $repositories, true)) {
            $propertyArrow = $tokens[$i - 3] ?? null;
            $object = $tokens[$i - 4] ?? null;

            return $propertyArrow !== null
                && in_array($propertyArrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && $object !== null && $object[0] === T_VARIABLE && $object[1] === '$this';
        }

        return false;
    }

    /**
     * Whether the expression closing at $close (a `)` or `]`) evaluates to the config
     * repository:
     *
     *  - `config()` / `\config()` with no argument;
     *  - `app(X)` / `resolve(X)` (bare or fully-qualified) where X names the repository —
     *    the `'config'` binding, or `Repository::class` resolving to a config repository;
     *  - `->make(X)` / `::make(X)` with the same X — `app()->make()`, `App::make()`,
     *    `Container::getInstance()->make()`, `$this->app->make()`;
     *  - `[...]['config']` — `$app['config']`, `$this->app['config']`, `app()['config']`.
     *
     * Anything else is some other object, and its `->get()` is not a config read — the same
     * line {@see self::configRepositoryNames()} draws for variables, drawn for expressions.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     */
    private function yieldsConfigRepository(array $tokens, int $close, array $imports): bool
    {
        $opener = $tokens[$close][1] === ')' ? '(' : '[';
        $open = $this->matchingOpen($tokens, $close, $opener, $tokens[$close][1]);

        if ($open === null) {
            return false;
        }

        $inner = array_slice($tokens, $open + 1, $close - $open - 1);

        if ($opener === '[') {
            return count($inner) === 1 && $inner[0][0] === T_CONSTANT_ENCAPSED_STRING
                && $this->stringValue($inner[0][1]) === 'config';
        }

        $callee = $tokens[$open - 1] ?? null;

        if ($callee === null) {
            return false;
        }

        if ($this->isFunctionName($callee, 'config')) {
            return $inner === [] && $this->isGlobalCall($tokens, $open - 1);
        }

        if ($this->isFunctionName($callee, 'app') || $this->isFunctionName($callee, 'resolve')) {
            return $this->isGlobalCall($tokens, $open - 1) && $this->namesConfigRepository($inner, $imports);
        }

        $before = $tokens[$open - 2] ?? null;

        return $callee[0] === T_STRING && $callee[1] === 'make'
            && $before !== null && in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            && $this->namesConfigRepository($inner, $imports);
    }

    /**
     * Whether a container argument names the config repository: the `'config'` binding or a
     * `Repository::class` that resolves (through the imports) to one.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     * @param  array<string, string>  $imports
     */
    private function namesConfigRepository(array $argument, array $imports): bool
    {
        if (count($argument) === 1 && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            return $this->stringValue($argument[0][1]) === 'config';
        }

        return count($argument) === 3
            && in_array($argument[0][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && $argument[1][0] === T_DOUBLE_COLON
            && strtolower($argument[2][1]) === 'class'
            && in_array($this->resolveName($argument[0][1], $imports), self::CONFIG_REPOSITORIES, true);
    }

    /**
     * The index of the bracket opening the one that closes at $close, or null if unbalanced.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function matchingOpen(array $tokens, int $close, string $open, string $closer): ?int
    {
        $depth = 0;

        for ($j = $close; $j >= 0; $j--) {
            if ($tokens[$j][1] === $closer) {
                $depth++;
            } elseif ($tokens[$j][1] === $open) {
                $depth--;

                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
    }

    /**
     * Whether a token is the global function `$name`, bare or fully-qualified.
     *
     * @param  array{0: int|null, 1: string}  $token
     */
    private function isFunctionName(array $token, string $name): bool
    {
        return ($token[0] === T_STRING && $token[1] === $name)
            || ($token[0] === T_NAME_FULLY_QUALIFIED && $token[1] === '\\'.$name);
    }

    /**
     * Whether the name at $i is called as a global function — not a method (`->config(`,
     * `::config(`), a declaration (`function config(`) or an instantiation (`new config(`).
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function isGlobalCall(array $tokens, int $i): bool
    {
        $prev = $tokens[$i - 1] ?? null;

        return $prev === null
            || ! in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $argument
     */
    private function isArrayArgument(array $argument): bool
    {
        $first = $argument[0] ?? null;

        return $first !== null && ($first[1] === '[' || $first[0] === T_ARRAY);
    }

    /**
     * The literal keys of an array argument: each element of a list, each key of a map.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     * @return list<array{0: int|null, 1: string}>
     */
    private function arrayKeys(array $argument): array
    {
        $keys = [];
        $depth = 0;
        $elementStart = true;

        foreach ($argument as $index => $token) {
            if (in_array($token[1], ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }

            if (in_array($token[1], [')', ']', '}'], true)) {
                $depth--;

                continue;
            }

            if ($depth !== 1) {
                continue;
            }

            if ($token[1] === ',') {
                $elementStart = true;

                continue;
            }

            if ($elementStart && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $after = $argument[$index + 1] ?? null;

                // A lone literal element, or the key of `'k' => $default`.
                if ($after === null || in_array($after[1], [',', ']', ')'], true) || $after[0] === T_DOUBLE_ARROW) {
                    $keys[] = $token;
                }
            }

            $elementStart = false;
        }

        return $keys;
    }

    /**
     * Every name in the file bound to a config repository by a **declared type** — promoted
     * constructor properties (`private readonly Repository $config`), plain properties, and
     * method parameters alike. Returned without the leading `$`, so one set answers for both
     * `$config` and `$this->config`.
     *
     * Typing is the whole point: matching on the *name* `config` would count
     * `$this->config->get()` on any object that happens to have such a property, and matching
     * any `->get('pkg.…')` would count a cache lookup under a key that merely shares the
     * prefix. Both invent reads, and an invented read blinds the reverse check on a key that
     * really is dead.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     * @return list<string>
     */
    private function configRepositoryNames(array $tokens, array $imports): array
    {
        $names = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $variable = $tokens[$i + 1] ?? null;

            // A type is only a binding when a variable immediately follows it. This also
            // skips the `use Illuminate\Contracts\Config\Repository;` import itself.
            if ($variable === null || $variable[0] !== T_VARIABLE) {
                continue;
            }

            if (in_array($this->resolveName($text, $imports), self::CONFIG_REPOSITORIES, true)) {
                $names[] = ltrim($variable[1], '$');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The file's `use` imports, keyed by lowercased alias — so `use ... Repository as Cfg;`
     * and a plain `use ... Repository;` both resolve.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return array<string, string>
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
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

            if (($tokens[$i + 2][0] ?? null) === T_AS && isset($tokens[$i + 3])) {
                $alias = $tokens[$i + 3][1];
            }

            $imports[strtolower($alias)] = $name[1];
        }

        return $imports;
    }

    /**
     * Resolve a type name as written into a fully-qualified name, using the file's imports.
     *
     * @param  array<string, string>  $imports
     */
    private function resolveName(string $name, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        $alias = strtolower($segments[0]);

        if (isset($imports[$alias])) {
            $segments[0] = $imports[$alias];

            return implode('\\', $segments);
        }

        return $name;
    }

    /**
     * Collect the tokens of the first argument to the call whose `(` is at $openIndex.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<array{0: int|null, 1: string}>
     */
    private function captureFirstArgument(array $tokens, int $openIndex): array
    {
        $argument = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $openIndex; $i < $count; $i++) {
            $char = $tokens[$i][1];

            if (in_array($char, ['(', '[', '{'], true)) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($char, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($char === ',' && $depth === 1) {
                break;
            }

            $argument[] = $tokens[$i];
        }

        return $argument;
    }

    /**
     * Sort a call argument into a read (literal or driver-keyed pattern), a wholesale
     * dynamic-section read, an interpolation to flag, or noise.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     * @param  list<string>  $reads
     * @param  list<string>  $interpolations
     * @param  list<string>  $dynamicSections
     */
    private function classifyArgument(array $argument, array &$reads, array &$interpolations, array &$dynamicSections): void
    {
        if (count($argument) === 1 && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = $this->stringValue($argument[0][1]);

            if (str_starts_with($value, $this->prefix.'.')) {
                $reads[] = $value;
            }

            return;
        }

        $skeleton = $this->skeleton($argument);
        $snippet = trim(implode('', array_column($argument, 1)));

        // Not built from literals and holes at all (`config($this->keyFor('x'))`) — report it
        // only if some literal fragment claims this prefix, else it is another prefix's key.
        if ($skeleton === null) {
            if ($this->mentionsPrefix($argument)) {
                $interpolations[] = $snippet;
            }

            return;
        }

        if (! str_starts_with(str_replace(self::HOLE, '*', $skeleton), $this->prefix.'.')) {
            return;
        }

        $pattern = $this->pattern($skeleton);

        // A hole that does not fill a whole segment (`"pkg.drivers.{$name}x"`) cannot be
        // reasoned about segment-wise, and guessing is how a check starts lying.
        if ($pattern === null) {
            $interpolations[] = $snippet;

            return;
        }

        $stripped = $this->stripTrailingHoles($pattern);

        // A read that stops *at* the hole is a wholesale section read — semantically the same
        // as the literal `config('pkg.drivers')` this contract already tolerates, so it
        // degrades to the literal parent: covered forward, proving no leaf in reverse.
        //
        // Except at the root. `config("pkg.{$name}")` strips down to the bare prefix, which
        // claims nothing a forward check could test — every key in the file trivially matches
        // it. Degrading that would trade a precise error for a vacuous read, so the most
        // opaque shape of all stays unresolvable and keeps its pressure to name a literal.
        if ($stripped === $this->prefix) {
            $interpolations[] = $snippet;

            return;
        }

        if ($stripped !== $pattern) {
            $dynamicSections[] = $stripped.'|'.$snippet;
        }

        $reads[] = $stripped;
    }

    /**
     * True when a literal fragment of the argument names this contract's prefix — used only
     * to decide whether an unresolvable expression is this contract's problem to report.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     */
    private function mentionsPrefix(array $argument): bool
    {
        foreach ($argument as [$id, $text]) {
            if (! in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $value = $id === T_CONSTANT_ENCAPSED_STRING ? $this->stringValue($text) : $text;

            if (str_starts_with($value, $this->prefix.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten a key expression into its literal text with a {@see self::HOLE} marker for each
     * interpolated or concatenated value, or null when it is not built from string literals
     * and holes alone.
     *
     * This is what makes a driver-keyed read checkable with no declaration from the test
     * author: `config("git.providers.{$key}.url")` carries its own skeleton —
     * `git.providers.<hole>.url` — right there in the token stream. The literal `url` after
     * the hole is the part being proven, and it is proven by the source, not asserted by a
     * test author who might be wrong.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     */
    private function skeleton(array $argument): ?string
    {
        $skeleton = '';
        $count = count($argument);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $argument[$i];

            // A brace hole — `{$key}`, `{$this->key()}`. Skipped whole: what is inside is a
            // runtime value, and no amount of reading it would name the driver.
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $close = $this->skipBraces($argument, $i);

                if ($close === null) {
                    return null;
                }

                $i = $close;
                $skeleton .= self::HOLE;

                continue;
            }

            if ($id === T_VARIABLE) {
                $skeleton .= self::HOLE;

                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $skeleton .= $this->stringValue($text);

                continue;
            }

            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                $skeleton .= $text;

                continue;
            }

            // The `"` delimiters of an interpolated string and the `.` concatenation operator
            // between fragments carry no key text of their own.
            if ($id === null && in_array($text, ['"', '.'], true)) {
                continue;
            }

            return null;
        }

        return $skeleton;
    }

    /**
     * The index of the `}` closing the brace hole opening at $i, or null if unbalanced.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     */
    private function skipBraces(array $argument, int $i): ?int
    {
        $depth = 0;
        $count = count($argument);

        for ($j = $i; $j < $count; $j++) {
            [$id, $text] = $argument[$j];

            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES || $text === '{') {
                $depth++;
            } elseif ($text === '}') {
                $depth--;

                if ($depth === 0) {
                    return $j;
                }
            }
        }

        return null;
    }

    /**
     * Turn a skeleton into a dotted pattern, or null when a hole does not occupy exactly one
     * whole segment.
     *
     * Segment alignment is the guard rail. `"pkg.providers.{$key}.url"` gives a hole bounded
     * by dots, so `*` cleanly stands for one driver name. `"pkg.providers.{$key}url"` does
     * not: the hole bleeds into its segment, and any pattern derived from it would be a
     * guess. That one goes back to the caller as an interpolation to fix by hand.
     */
    private function pattern(string $skeleton): ?string
    {
        $segments = explode('.', $skeleton);

        foreach ($segments as $index => $segment) {
            if (! str_contains($segment, self::HOLE)) {
                continue;
            }

            if ($segment !== self::HOLE) {
                return null;
            }

            $segments[$index] = '*';
        }

        return implode('.', $segments);
    }

    /**
     * Drop trailing `*` segments, leaving the literal parent path.
     *
     * A pattern ending in a hole proves nothing per-leaf, so it must not masquerade as a
     * per-leaf proof. Reducing it to the parent is exactly right: `config("pkg.drivers.{$k}")`
     * reads the `pkg.drivers` subtree wholesale, and a wholesale read has always been
     * forward-covered but reverse-worthless here.
     */
    private function stripTrailingHoles(string $pattern): string
    {
        $segments = explode('.', $pattern);

        while ($segments !== [] && end($segments) === '*') {
            array_pop($segments);
        }

        return implode('.', $segments);
    }

    /**
     * The `sectionVariables` name the receiver starting at $i denotes, plus the index of its
     * last token — so the caller knows where the `['offset']` chain begins.
     *
     * Two shapes, because a package that holds its config section on a property is as
     * ordinary as one that holds it in a local:
     *
     *   - `$config['x']`         → one token,    name `$config`
     *   - `$this->config['x']`   → three tokens, name `$this->config`
     *
     * The property form was invisible: the scrape matched a lone `T_VARIABLE` against the
     * mapping, and `$this->config` is `$this` + `->` + `config`, so the mapping was never
     * consulted and every leaf under it scraped as unread. That is the reverse check
     * inventing a dead key — the same false report `allowUnread` would then have been used
     * to silence, asserting a falsehood about live keys.
     *
     * Anchored on `$this`: an unrelated `$other->config['x']` is a different object's
     * property and is not attributed to this class's mapping.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return array{0: string|null, 1: int}
     */
    private function sectionReceiver(array $tokens, int $i): array
    {
        $arrow = $tokens[$i + 1] ?? null;
        $property = $tokens[$i + 2] ?? null;

        if ($tokens[$i][1] === '$this'
            && $arrow !== null && in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $property !== null && $property[0] === T_STRING) {
            return ['$this->'.$property[1], $i + 2];
        }

        return [$tokens[$i][1], $i];
    }

    /**
     * The dotted path the receiver ending at $i is indexed by, following **every** consecutive
     * string-literal offset: `$var['a']` gives `a`, and `$var['a']['b']` gives `a.b`.
     *
     * Depth is the point. This used to read exactly one offset, so a package that took its
     * config section wholesale and indexed two levels in — `$config['public']['enabled']` —
     * registered a read of `pkg.public`, which by design does not prove anything about the
     * leaf `pkg.public.enabled`. Every leaf therefore scraped as **unread**, a report
     * indistinguishable from media #27 (a shipped `max_file_size` cap that never applied).
     * `cosmos-foundation` hit exactly this on `rate_limiters.*.{enabled,per_minute,guard}`
     * and worked around it by unrolling every leaf into a literal `config()` call — the good
     * outcome, since the tempting one was `allowUnread`, which would have asserted a
     * falsehood about live keys.
     *
     * A non-literal offset (`$var['a'][$name]`) stops the walk rather than guessing, so the
     * read degrades to the parent path and the leaves below it stay unproven — a visible
     * failure, never a silent pass.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function offsetRead(array $tokens, int $i): ?string
    {
        $segments = [];
        $j = $i + 1;

        while (true) {
            $open = $tokens[$j] ?? null;
            $key = $tokens[$j + 1] ?? null;
            $close = $tokens[$j + 2] ?? null;

            if ($open === null || $open[1] !== '['
                || $key === null || $key[0] !== T_CONSTANT_ENCAPSED_STRING
                || $close === null || $close[1] !== ']') {
                break;
            }

            $segments[] = $this->stringValue($key[1]);
            $j += 3;
        }

        return $segments === [] ? null : implode('.', $segments);
    }

    private function stringValue(string $raw): string
    {
        $quote = $raw[0] ?? '';
        $inner = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }
}
