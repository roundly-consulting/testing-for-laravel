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
 *   - a literal key handed to one of package-toolkit-for-laravel's readers — see
 *     {@see self::toolkitRead()} for the full set, static and chained;
 *   - any literal string under one of `extraReadPrefixes`, wherever it appears.
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
 *
 * ## Toolkit readers
 *
 * Every roundly package reads its config through package-toolkit-for-laravel's strict readers
 * — `Config::enum()`, `Config::using(…)->integer()`, `ModelResolver::for()`, `bindFromConfig()`
 * — far more often than through a bare `config()`. Only `Config::boolean()` / `integer()` used
 * to be visible here, so every other reader scraped as *no read*, and the fleet worked around
 * it with `extraReadPrefixes` (which counts every literal under a prefix, routes filenames
 * included) or `allowUnread` (which asserts a live key is dead). They are recognised by the
 * same rule as the injected repository: by what the receiver **is** — the toolkit class
 * through the file's imports, a declared `ConfigValidator`, a toolkit provider or `Package` —
 * never by a method name alone.
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
     * The repository methods that *read* a key by its first argument — `getMany()` reads each
     * key of the array it is handed. `set()` and `push()` are deliberately absent: writing a
     * key is not evidence anything consumes it, and counting a write as a read would let a key
     * that is only ever set pass the reverse check — exactly the dead-key class it exists to
     * catch.
     *
     * @var list<string>
     */
    private const array READ_METHODS = [
        'get', 'getMany', 'has', 'string', 'integer', 'boolean', 'float', 'array', 'collection',
    ];

    /**
     * The toolkit's strict readers — on `Config` statically, and on any `ConfigValidator` —
     * each taking the key as its first argument. Lowercased: PHP method names are not case
     * sensitive, so neither is the match.
     *
     * @var list<string>
     */
    private const array STRICT_READ_METHODS = ['boolean', 'integer', 'enum', 'oneof', 'requirestring'];

    private const string TOOLKIT_CONFIG = 'RoundlyConsulting\PackageToolkit\Support\Config';

    private const string CONFIG_VALIDATOR = 'RoundlyConsulting\PackageToolkit\Support\ConfigValidator';

    private const string MODEL_RESOLVER = 'RoundlyConsulting\PackageToolkit\Support\ModelResolver';

    private const string KEY_TYPE = 'RoundlyConsulting\PackageToolkit\Enums\KeyType';

    private const string PACKAGE = 'RoundlyConsulting\PackageToolkit\Package';

    private const string PACKAGE_PROVIDER = 'RoundlyConsulting\PackageToolkit\PackageServiceProvider';

    private const string RESOLVES_MODELS = 'RoundlyConsulting\PackageToolkit\Concerns\ResolvesModels';

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
        $repositories = $this->typedNames($tokens, $imports, self::CONFIG_REPOSITORIES);
        $toolkit = $this->toolkitContext($tokens, $imports);

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            $helper = $this->opensConfigHelper($tokens, $i);

            if ($helper || $this->opensFacadeRead($tokens, $i, $imports) || $this->opensRepositoryRead($tokens, $i, $repositories, $imports)) {
                // The key by name (`config(key: 'pkg.x')`, in any position) or else the first
                // argument — never a `key:` label mistaken for part of the key.
                $argument = $this->callArgument($tokens, $i + 1, 0, $text === 'getMany' ? 'keys' : 'key') ?? [];

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

            $toolkitRead = $this->toolkitRead($tokens, $i, $imports, $toolkit);

            if ($toolkitRead !== null) {
                // A validator over a HANDED array (`Config::for($values)`) names its key only to
                // label the failure: the value was read elsewhere, by a read this scrape sees on
                // its own. An unresolvable label there hides no read, so it is not flagged.
                [$key, $handed] = $toolkitRead;
                $this->classifyArgument($key, $reads, $interpolations, $dynamicSections, flagUnresolvable: ! $handed);

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
     * `PackageToolkit\Support\Config` helpers read keys by their first argument the same way,
     * and so do its strict-only readers (`enum`, `oneOf`, `requireString`).
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     */
    private function opensFacadeRead(array $tokens, int $i, array $imports): bool
    {
        [$id, $text] = $tokens[$i];

        if ($id !== T_STRING
            || (! in_array($text, self::READ_METHODS, true) && ! in_array(strtolower($text), self::STRICT_READ_METHODS, true))) {
            return false;
        }

        $next = $tokens[$i + 1] ?? null;
        $colons = $tokens[$i - 1] ?? null;
        $class = $tokens[$i - 2] ?? null;

        if ($next === null || $next[1] !== '(' || $colons === null || $colons[0] !== T_DOUBLE_COLON || $class === null
            || ! in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        return $this->namesConfigClass($class[1], $imports);
    }

    /**
     * Whether a class name as written is the `Config` facade or the toolkit's `Config` — bare,
     * fully-qualified, or under an import alias.
     *
     * @param  array<string, string>  $imports
     */
    private function namesConfigClass(string $name, array $imports): bool
    {
        return $name === 'Config'
            || in_array($this->resolveName($name, $imports), ['Illuminate\Support\Facades\Config', 'Config', self::TOOLKIT_CONFIG], true);
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
     * line {@see self::typedNames()} draws for variables, drawn for expressions.
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
     * Every name in the file bound to one of `$types` by a **declared type** — promoted
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
     * @param  list<string>  $types  fully-qualified class names
     * @return list<string>
     */
    private function typedNames(array $tokens, array $imports, array $types): array
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

            if (in_array($this->resolveName($text, $imports), $types, true)) {
                $names[] = ltrim($variable[1], '$');
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * What the file declares about the toolkit, collected once so {@see self::toolkitRead()}
     * can stay a per-token check:
     *
     *  - `validatorMethods` — methods declared to return a `ConfigValidator`
     *    (`private static function validator(): ConfigValidator`), lowercased;
     *  - `validators` — names holding one, by declared type or by assignment from a validator
     *    expression (`$read = Config::for($values);`), each mapped to whether it validates a
     *    handed array (see {@see self::validatorExpression()});
     *  - `packages` — names typed `Package` (the `configurePackage(Package $package)` builder);
     *  - `provider` — the file declares a class extending the toolkit's `PackageServiceProvider`;
     *  - `resolvesModels` — the file imports the toolkit's `ResolvesModels` trait.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     * @return array{validatorMethods: list<string>, validators: array<string, bool>, packages: list<string>, provider: bool, resolvesModels: bool}
     */
    private function toolkitContext(array $tokens, array $imports): array
    {
        $context = [
            'validatorMethods' => $this->methodsReturning($tokens, $imports, self::CONFIG_VALIDATOR),
            'validators' => array_fill_keys($this->typedNames($tokens, $imports, [self::CONFIG_VALIDATOR]), false),
            'packages' => $this->typedNames($tokens, $imports, [self::PACKAGE]),
            'provider' => false,
            'resolvesModels' => in_array(self::RESOLVES_MODELS, $imports, true),
        ];

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $next = $tokens[$i + 1] ?? null;

            if ($tokens[$i][0] === T_EXTENDS && $next !== null
                && $this->resolveName($next[1], $imports) === self::PACKAGE_PROVIDER) {
                $context['provider'] = true;
            }

            // `$read = Config::for($values);` — the validator is assigned, then read through.
            if ($tokens[$i][0] === T_VARIABLE && $next !== null && $next[1] === '=') {
                $end = $this->statementEnd($tokens, $i + 2);
                $expression = $end !== null && $tokens[$end - 1][1] === ')'
                    ? $this->validatorExpression($tokens, $end - 1, $imports, $context['validatorMethods'])
                    : null;

                if ($expression !== null && $expression[0] === $i + 2) {
                    $context['validators'][ltrim($tokens[$i][1], '$')] = $expression[1];
                }
            }
        }

        return $context;
    }

    /**
     * Lowercased names of the methods the file declares with return type `$type`.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     * @return list<string>
     */
    private function methodsReturning(array $tokens, array $imports, string $type): array
    {
        $methods = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $name = $this->memberName($tokens[$i + 1] ?? null);
            $open = $i + 2;

            if ($name === null || ($tokens[$open][1] ?? null) !== '(') {
                continue;
            }

            $close = $this->matchingClose($tokens, $open);
            $colon = $close === null ? null : ($tokens[$close + 1] ?? null);

            if ($close === null || $colon === null || $colon[1] !== ':') {
                continue;
            }

            $returns = $tokens[$close + 2] ?? null;

            if ($returns !== null && $returns[1] === '?') {
                $returns = $tokens[$close + 3] ?? null;
            }

            if ($returns !== null && in_array($returns[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && $this->resolveName($returns[1], $imports) === $type) {
                $methods[] = $name;
            }
        }

        return $methods;
    }

    /**
     * The key argument when the token at $i names one of package-toolkit-for-laravel's config
     * readers and opens its call, or null. Each is recognised by what its receiver IS:
     *
     *  - `ModelResolver::for('k')` / `::newModel('k')`, `KeyType::fromConfig('k')` — the
     *    toolkit class, resolved through the imports;
     *  - `->boolean|integer|enum|oneOf|requireString('k')` on a **validator**: the expression
     *    `Config::using(…)` / `Config::for(…)` / `ConfigValidator::forRepository|forArray(…)`, a
     *    method declared to return one (`self::validator()->…`), or a name declared or
     *    assigned as one;
     *  - `$this->bindFromConfig(Contract::class, 'k', …)` and `$this->observesModel('k', …)` in a
     *    class extending the toolkit's `PackageServiceProvider`;
     *  - `$this->modelClass('k')` / `->newModel('k')` in a class using `ResolvesModels`;
     *  - `->hasRoutes('file.php', 'k')` / `->hasFacadeAlias(X::class, 'k')` on a chain rooted at
     *    a `Package` — the switch only, never the routes filename beside it.
     *
     * (The static `Config::enum('k')` family is {@see self::opensFacadeRead()}'s.) Named
     * arguments resolve by parameter name, so `enabledVia: 'k'` is found wherever it sits.
     *
     * Returned with whether the read validates a handed array rather than the repository.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     * @param  array{validatorMethods: list<string>, validators: array<string, bool>, packages: list<string>, provider: bool, resolvesModels: bool}  $context
     * @return array{0: list<array{0: int|null, 1: string}>, 1: bool}|null
     */
    private function toolkitRead(array $tokens, int $i, array $imports, array $context): ?array
    {
        $method = $this->memberName($tokens[$i]);
        $separator = $tokens[$i - 1] ?? null;
        $receiver = $tokens[$i - 2] ?? null;

        if ($method === null || ($tokens[$i + 1][1] ?? null) !== '(' || $separator === null || $receiver === null) {
            return null;
        }

        if ($separator[0] === T_DOUBLE_COLON) {
            $class = in_array($receiver[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STATIC], true)
                ? $this->resolveName($receiver[1], $imports)
                : null;

            $reads = ($class === self::MODEL_RESOLVER && in_array($method, ['for', 'newmodel'], true))
                || ($class === self::KEY_TYPE && $method === 'fromconfig')
                || ($context['resolvesModels'] && in_array($class, ['self', 'static'], true) && in_array($method, ['modelclass', 'newmodel'], true));

            return $reads ? $this->keyRead($tokens, $i + 1, 0, 'key') : null;
        }

        if (! in_array($separator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return null;
        }

        if (in_array($method, self::STRICT_READ_METHODS, true)) {
            $handed = $this->validatorReceiver($tokens, $i - 2, $imports, $context);

            return $handed === null ? null : $this->keyRead($tokens, $i + 1, 0, 'key', $handed);
        }

        $onThis = $receiver[0] === T_VARIABLE && $receiver[1] === '$this';

        return match (true) {
            $onThis && $context['provider'] && $method === 'bindfromconfig' => $this->keyRead($tokens, $i + 1, 1, 'configKey'),
            $onThis && $context['provider'] && $method === 'observesmodel' => $this->keyRead($tokens, $i + 1, 0, 'configKey'),
            $onThis && $context['resolvesModels'] && in_array($method, ['modelclass', 'newmodel'], true) => $this->keyRead($tokens, $i + 1, 0, 'key'),
            $method === 'hasroutes' && $this->chainedOffPackage($tokens, $i - 2, $context['packages']) => $this->keyRead($tokens, $i + 1, 1, 'enabledVia'),
            $method === 'hasfacadealias' && $this->chainedOffPackage($tokens, $i - 2, $context['packages']) => $this->keyRead($tokens, $i + 1, 1, 'configKey'),
            default => null,
        };
    }

    /**
     * Whether the receiver ending at $end — the token left of `->` — is a `ConfigValidator`:
     * null when it is not, else whether it validates a handed array. A validator expression,
     * or a local / `$this->` property declared or assigned as one.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     * @param  array{validatorMethods: list<string>, validators: array<string, bool>, packages: list<string>, provider: bool, resolvesModels: bool}  $context
     */
    private function validatorReceiver(array $tokens, int $end, array $imports, array $context): ?bool
    {
        $receiver = $tokens[$end];

        if ($receiver[1] === ')') {
            return $this->validatorExpression($tokens, $end, $imports, $context['validatorMethods'])[1] ?? null;
        }

        if ($receiver[0] === T_VARIABLE) {
            return $context['validators'][ltrim($receiver[1], '$')] ?? null;
        }

        $arrow = $tokens[$end - 1] ?? null;
        $object = $tokens[$end - 2] ?? null;

        $property = $receiver[0] === T_STRING
            && $arrow !== null && in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $object !== null && $object[1] === '$this';

        return $property ? ($context['validators'][$receiver[1]] ?? null) : null;
    }

    /**
     * Where the validator expression closing at $close starts, and whether it validates a
     * **handed** array — or null when the call closing there does not yield a
     * `ConfigValidator`:
     *
     *  - `Config::using(…)` on the toolkit's `Config` / `ConfigValidator::forRepository(…)` —
     *    the config repository;
     *  - `Config::for(…)` / `ConfigValidator::forArray(…)` — a handed array;
     *  - `self::m(…)` / `static::m(…)` / `$this->m(…)` where `m` is declared to return one —
     *    taken as the repository, the conservative side: its unresolvable keys stay flagged.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  array<string, string>  $imports
     * @param  list<string>  $validatorMethods
     * @return array{0: int, 1: bool}|null
     */
    private function validatorExpression(array $tokens, int $close, array $imports, array $validatorMethods): ?array
    {
        $open = $this->matchingOpen($tokens, $close, '(', ')');

        if ($open === null) {
            return null;
        }

        $method = $this->memberName($tokens[$open - 1] ?? null);
        $separator = $tokens[$open - 2] ?? null;
        $receiver = $tokens[$open - 3] ?? null;

        if ($method === null || $separator === null || $receiver === null) {
            return null;
        }

        if ($separator[0] === T_DOUBLE_COLON) {
            $class = $this->resolveName($receiver[1], $imports);
            $toolkitConfig = $this->namesConfigClass($receiver[1], $imports) && $class !== 'Illuminate\Support\Facades\Config';

            return match (true) {
                $toolkitConfig && $method === 'using', $class === self::CONFIG_VALIDATOR && $method === 'forrepository' => [$open - 3, false],
                $toolkitConfig && $method === 'for', $class === self::CONFIG_VALIDATOR && $method === 'forarray' => [$open - 3, true],
                in_array($receiver[1], ['self', 'static'], true) && in_array($method, $validatorMethods, true) => [$open - 3, false],
                default => null,
            };
        }

        return in_array($separator[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $receiver[1] === '$this' && in_array($method, $validatorMethods, true)
            ? [$open - 3, false]
            : null;
    }

    /**
     * Whether the fluent chain whose last receiver ends at $end is rooted at a name typed
     * `Package` — walking back over every `->method(…)` link to the variable that starts it.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  list<string>  $packages
     */
    private function chainedOffPackage(array $tokens, int $end, array $packages): bool
    {
        $position = $end;

        while (($tokens[$position] ?? null) !== null && $tokens[$position][1] === ')') {
            $open = $this->matchingOpen($tokens, $position, '(', ')');
            $arrow = $open === null ? null : ($tokens[$open - 2] ?? null);

            if ($open === null || $this->memberName($tokens[$open - 1] ?? null) === null
                || $arrow === null || ! in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                return false;
            }

            $position = $open - 3;
        }

        $root = $tokens[$position] ?? null;

        return $root !== null && $root[0] === T_VARIABLE && in_array(ltrim($root[1], '$'), $packages, true);
    }

    /**
     * {@see self::callArgument()} paired with whether it validates a handed array, or null
     * when the call has no such argument.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return array{0: list<array{0: int|null, 1: string}>, 1: bool}|null
     */
    private function keyRead(array $tokens, int $open, int $position, string $parameter, bool $handed = false): ?array
    {
        $argument = $this->callArgument($tokens, $open, $position, $parameter);

        return $argument === null ? null : [$argument, $handed];
    }

    /**
     * The tokens of one argument of the call whose `(` is at $open: the named argument
     * `$parameter:` when the call names it, else the one at `$position`. Null when absent.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<array{0: int|null, 1: string}>|null
     */
    private function callArgument(array $tokens, int $open, int $position, string $parameter): ?array
    {
        $positional = [];

        foreach ($this->callArguments($tokens, $open) as $argument) {
            // `name: value` — an identifier, then a lone `:` (`::` is its own token).
            if (count($argument) > 2 && $argument[1][1] === ':' && preg_match('/^[A-Za-z_]\w*$/', $argument[0][1]) === 1) {
                if ($argument[0][1] === $parameter) {
                    return array_slice($argument, 2);
                }

                continue;
            }

            $positional[] = $argument;
        }

        return $positional[$position] ?? null;
    }

    /**
     * The top-level arguments of the call whose `(` is at $open, each as its tokens.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<list<array{0: int|null, 1: string}>>
     */
    private function callArguments(array $tokens, int $open): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($j = $open; $j < $count; $j++) {
            [$id, $text] = $tokens[$j];

            if (in_array($text, ['(', '[', '{'], true) || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                if (++$depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $tokens[$j];
        }

        // A trailing comma leaves nothing behind it, and nothing is not an argument.
        if ($current !== []) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    /**
     * The index of the `;` ending the statement that continues at $start, or null.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function statementEnd(array $tokens, int $start): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($j = $start; $j < $count; $j++) {
            $text = $tokens[$j][1];

            if (in_array($text, ['(', '[', '{'], true) || $tokens[$j][0] === T_CURLY_OPEN || $tokens[$j][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth < 0) {
                    return null;
                }
            } elseif ($text === ';' && $depth === 0) {
                return $j;
            }
        }

        return null;
    }

    /**
     * The index of the bracket closing the one that opens at $open, or null if unbalanced.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function matchingClose(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($j = $open; $j < $count; $j++) {
            if ($tokens[$j][1] === '(') {
                $depth++;
            } elseif ($tokens[$j][1] === ')' && --$depth === 0) {
                return $j;
            }
        }

        return null;
    }

    /**
     * A method name as written after `->`, `::` or `function`, lowercased — or null when the
     * token is not an identifier. After `::` and `function` PHP keeps a reserved word's own
     * token (`ModelResolver::for` is `T_FOR`), so the text decides, not the token id.
     *
     * @param  array{0: int|null, 1: string}|null  $token
     */
    private function memberName(?array $token): ?string
    {
        return $token !== null && $token[0] !== null && preg_match('/^[A-Za-z_]\w*$/', $token[1]) === 1
            ? strtolower($token[1])
            : null;
    }

    /**
     * The file's `use` imports, keyed by lowercased alias — so `use ... Repository as Cfg;`
     * and a plain `use ... Repository;` both resolve.
     *
     * Only a `use` outside every class body is an import. Inside one it is a trait use
     * (`use ResolvesModels;`), and reading it as an import would rebind the alias to the bare
     * short name — un-resolving the real import of the same name above it.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return array<string, string>
     */
    private function imports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);
        $braces = [];
        $namespaceOpen = false;

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if ($id === T_NAMESPACE) {
                $namespaceOpen = true;
            } elseif ($text === ';') {
                $namespaceOpen = false;
            }

            if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                // A braced `namespace Foo { … }` still holds imports; any other brace does not.
                $braces[] = $namespaceOpen;
                $namespaceOpen = false;

                continue;
            }

            if ($text === '}') {
                array_pop($braces);

                continue;
            }

            if ($id !== T_USE || in_array(false, $braces, true)) {
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
     * Sort a call argument into a read (literal or driver-keyed pattern), a wholesale
     * dynamic-section read, an interpolation to flag, or noise.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     * @param  list<string>  $reads
     * @param  list<string>  $interpolations
     * @param  list<string>  $dynamicSections
     * @param  bool  $flagUnresolvable  false where an unresolvable key hides no read (a label)
     */
    private function classifyArgument(array $argument, array &$reads, array &$interpolations, array &$dynamicSections, bool $flagUnresolvable = true): void
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
            if ($flagUnresolvable && $this->mentionsPrefix($argument)) {
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
            if ($flagUnresolvable) {
                $interpolations[] = $snippet;
            }

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
            if ($flagUnresolvable) {
                $interpolations[] = $snippet;
            }

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
