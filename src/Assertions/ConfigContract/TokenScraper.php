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
 *   - a literal string argument to `config('pkg.…')` / `Config::get('pkg.…')`;
 *   - a literal string argument to a read method on an **injected config repository** —
 *     `$this->config->get('pkg.…')`, `$config->string('pkg.…')` (see below);
 *   - a `$var['key']` array-offset read where `$var` is mapped to a base path via
 *     `sectionVariables` (a package that hands its whole config array to a DTO reads
 *     keys by offset, not through `config()`);
 *   - any literal string under one of `extraReadPrefixes`, wherever it appears
 *     (e.g. `ModelResolver::for('pkg.model')` — not a `config()` call).
 *
 * A `config("pkg.{$x}")` interpolation or `config('pkg.'.$x)` concatenation under the
 * prefix is never silently treated as "reads nothing"; it is collected as an
 * interpolation so the contract can fail and demand a literal key or an allow-list.
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
        $tokens = $this->meaningfulTokens((string) file_get_contents($file));

        $reads = [];
        $interpolations = [];

        $repositories = $this->configRepositoryNames($tokens);

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if ($this->opensConfigCall($tokens, $i) || $this->opensRepositoryRead($tokens, $i, $repositories)) {
                $argument = $this->captureFirstArgument($tokens, $i + 1);
                $this->classifyArgument($argument, $reads, $interpolations);

                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $value = $this->stringValue($text);

                foreach ($this->extraReadPrefixes as $extra) {
                    if (str_starts_with($value, $extra)) {
                        $reads[] = $value;
                    }
                }
            }

            if ($id === T_VARIABLE && array_key_exists($text, $sectionVariables)) {
                $offset = $this->offsetRead($tokens, $i);

                if ($offset !== null) {
                    $reads[] = $sectionVariables[$text].'.'.$offset;
                }
            }
        }

        return new ScrapedFile(array_values(array_unique($reads)), array_values(array_unique($interpolations)));
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
     * True when the token at $i begins a `config(` or `Config::get(` call — and is a
     * real call, not a `$this->config` property or a `Something::config` reference.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function opensConfigCall(array $tokens, int $i): bool
    {
        [$id, $text] = $tokens[$i];
        $next = $tokens[$i + 1] ?? null;

        if ($id === T_STRING && $text === 'config') {
            $prev = $tokens[$i - 1] ?? null;

            if ($prev !== null && in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                return false;
            }

            return $next !== null && $next[1] === '(';
        }

        // `Config::get('pkg.…')` and the rest of the facade's read family — the same methods
        // an injected repository exposes, reached statically.
        if ($id === T_STRING && in_array($text, self::READ_METHODS, true)) {
            $prev = $tokens[$i - 1] ?? null;
            $prevPrev = $tokens[$i - 2] ?? null;

            return $next !== null && $next[1] === '('
                && $prev !== null && $prev[0] === T_DOUBLE_COLON
                && $prevPrev !== null && $prevPrev[0] === T_STRING && $prevPrev[1] === 'Config';
        }

        return false;
    }

    /**
     * True when the token at $i is a read method invoked on a config repository — i.e.
     * `$this->config->get(` or `$config->get(`, where the receiver's *declared type* is a
     * config `Repository`.
     *
     * Both shapes reduce to the same question: the name immediately left of `->method(` must
     * be a known repository. Like {@see self::opensConfigCall()}, this reports the index of
     * the method name, so the caller's `$i + 1` is the opening paren either way.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @param  list<string>  $repositories  names (no leading `$`) bound to a config repository
     */
    private function opensRepositoryRead(array $tokens, int $i, array $repositories): bool
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
     * @return list<string>
     */
    private function configRepositoryNames(array $tokens): array
    {
        $imports = $this->imports($tokens);
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
     * Sort a call argument into a literal read, an interpolation to flag, or noise.
     *
     * @param  list<array{0: int|null, 1: string}>  $argument
     * @param  list<string>  $reads
     * @param  list<string>  $interpolations
     */
    private function classifyArgument(array $argument, array &$reads, array &$interpolations): void
    {
        if (count($argument) === 1 && $argument[0][0] === T_CONSTANT_ENCAPSED_STRING) {
            $value = $this->stringValue($argument[0][1]);

            if (str_starts_with($value, $this->prefix.'.')) {
                $reads[] = $value;
            }

            return;
        }

        $assembled = '';
        $hasVariable = false;

        foreach ($argument as [$id, $text]) {
            if ($id === T_VARIABLE) {
                $hasVariable = true;
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING) {
                $assembled .= $this->stringValue($text);
            } elseif ($id === T_ENCAPSED_AND_WHITESPACE) {
                $assembled .= $text;
            }
        }

        if ($hasVariable && str_starts_with($assembled, $this->prefix.'.')) {
            $interpolations[] = trim(implode('', array_column($argument, 1)));
        }
    }

    /**
     * The dotted path the variable at $i is indexed by, following **every** consecutive
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
