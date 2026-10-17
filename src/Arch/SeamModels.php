<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Illuminate\Database\Eloquent\Model;

/**
 * Which models a package lets a host swap, read from the package's **own seam**, not guessed.
 *
 * ## What counts as a seam method
 *
 * A method declared in a file under `$seamDir` that is:
 *
 *  - `static`, takes **no parameters** and declares a **`string`** return type — the shape of a
 *    resolver that hands back a class-string (`CommentModel::class()`, `TicketModels::ticket()`,
 *    `SluggableConfig::historyModel()`); and
 *  - names, in its body, an **Eloquent model** by `X::class` **and** a **config key** literal (a
 *    dotted key such as `'comments.model'` or `'alerts.silence-model'`) — the default and the swap
 *    key, side by side, which is what a swap seam is: `ModelResolver::for('comments.model',
 *    Comment::class)`, `config('x.model', X::class)`, a hand-written read-then-`is_a` check. A
 *    `self::KEY` / `self::DEFAULT` read of a class constant holding the literal or the `X::class`
 *    counts as the value it holds.
 *
 * Every model named that way is **swappable**, and that method is its seam. Measured on the 31
 * packages adopting {@see ArchPresets::modelsResolveThroughSeam()} on 2026-10-17, this finds
 * exactly the models each package maps in its own `swappableModelsAreNotFinal()` — 66 models,
 * 31 of 31 packages identical — including `alerts.alert` and `alerts.health-check`, which no
 * key-shape inference can see.
 *
 * ## Why read the code rather than the config or the declared keys
 *
 * - **Not the config value**: resolving `config('comments.model')` needs a booted app, so the
 *   strength of an arch case would depend on whether its file is bound to a TestCase — the same
 *   reason {@see ModelSeam} does not resolve key values.
 * - **Not the key list alone**: a declared key says *that* something is swappable, not *which
 *   class* the package names when it bypasses the seam. Only the seam's default says that.
 * - **Not a naming convention** (`*Model`, `*ModelResolver`): the fleet has both, plus
 *   multi-model seams (`auth`'s `Models`, `tickets`' `TicketModels`) and `SluggableConfig`. A
 *   name-based rule would cover some and skip the rest without saying so.
 *
 * Whether `X` is a model is resolved from the source's `extends` chain first, then by
 * reflection; an `X` that is neither is not counted (an interface or a contract named for an
 * `is_a` check, Eloquent's own `Model` base).
 *
 * ## What it cannot see, stated
 *
 * A seam that takes its key or its default as an argument, reads them from another class, or
 * builds them at runtime has no key literal and no `X::class` side by side, so it is not found.
 * {@see ModelSeam} turns that blind spot into a failure for every **declared** swap key — a
 * declared key that no seam method reads beside a default model is reported, never silently
 * skipped.
 *
 * @internal
 */
final readonly class SeamModels
{
    /** A config key: two or more dotted segments of letters, digits, `_` and `-`. */
    private const string CONFIG_KEY = '/^[a-z0-9_-]+(?:\.[a-z0-9_-]+)+$/i';

    /**
     * @param  array<string, list<string>>  $seams  model => its seam methods (`Fq\Seam::method`)
     * @param  array<string, list<string>>  $byCall  lower-case `fq\seam::method` => the models it resolves
     * @param  list<string>  $keys  the config-key literals read inside seam methods
     */
    private function __construct(
        private array $seams,
        private array $byCall,
        private array $keys,
    ) {}

    /**
     * @param  array<string, PhpSource>  $sources  every file of the package's `src/`, keyed by path
     */
    public static function discover(array $sources, string $srcDir, string $seamDir): self
    {
        $parents = self::parents($sources);
        $seams = [];
        $byCall = [];
        $keys = [];

        foreach ($sources as $file => $source) {
            if (! self::inSeam($srcDir, $file, $seamDir) || $source->class === null) {
                continue;
            }

            self::collect($source, $source->class, $parents, $seams, $byCall, $keys);
        }

        ksort($seams);

        return new self($seams, $byCall, array_values(array_unique($keys)));
    }

    /**
     * Class => parent, for every class the sources declare.
     *
     * @param  array<string, PhpSource>  $sources
     * @return array<string, string>
     */
    public static function parents(array $sources): array
    {
        $parents = [];

        foreach ($sources as $source) {
            if ($source->class !== null && $source->parent !== null) {
                $parents[$source->class] = $source->parent;
            }
        }

        return $parents;
    }

    public static function inSeam(string $srcDir, string $file, string $seamDir): bool
    {
        return str_starts_with(self::relative($srcDir, $file), trim($seamDir, '/').'/');
    }

    public static function relative(string $srcDir, string $file): string
    {
        return ltrim(str_replace(rtrim($srcDir, '/'), '', $file), '/');
    }

    /**
     * @return list<string>
     */
    public function models(): array
    {
        return array_keys($this->seams);
    }

    public function isSwappable(string $class): bool
    {
        return $this->model($class) !== null;
    }

    /**
     * The swappable model `$class` names, in its canonical spelling — or null.
     */
    public function model(string $class): ?string
    {
        foreach (array_keys($this->seams) as $model) {
            if (strcasecmp($model, $class) === 0) {
                return $model;
            }
        }

        return null;
    }

    /**
     * The seam methods that resolve `$model`, as `Fq\Seam::method`.
     *
     * @return list<string>
     */
    public function seamsFor(string $model): array
    {
        return $this->seams[$this->model($model) ?? $model] ?? [];
    }

    /**
     * The models a call to `Fq\Class::method()` resolves, empty when it is no seam method.
     *
     * @return list<string>
     */
    public function resolvedBy(string $call): array
    {
        return $this->byCall[strtolower($call)] ?? [];
    }

    /**
     * Whether a seam method reads `$key` beside the default model it resolves.
     */
    public function readsKey(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /**
     * Record every seam method `$source` declares: the models it resolves and the keys it reads.
     *
     * @param  array<string, string>  $parents
     * @param  array<string, list<string>>  $seams
     * @param  array<string, list<string>>  $byCall
     * @param  list<string>  $keys
     */
    private static function collect(PhpSource $source, string $seam, array $parents, array &$seams, array &$byCall, array &$keys): void
    {
        $tokens = $source->tokens;
        $count = count($tokens);
        $constants = self::constants($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION || ! PhpSource::isIdentifier($tokens[$i + 1] ?? null)) {
                continue;
            }

            if (! self::isStatic($tokens, $i)
                || ($tokens[$i + 2][1] ?? '') !== '('
                || ($tokens[$i + 3][1] ?? '') !== ')'
                || ($tokens[$i + 4][1] ?? '') !== ':'
                || strtolower($tokens[$i + 5][1] ?? '') !== 'string'
                || ($tokens[$i + 6][1] ?? '') !== '{') {
                continue;
            }

            $end = PhpSource::closing($tokens, $i + 6);
            $body = self::inlineConstants(array_slice($tokens, $i + 7, $end - $i - 7), $constants);
            $models = [];
            $methodKeys = [];

            foreach ($body as $j => [$id, $text]) {

                if ($id === T_CONSTANT_ENCAPSED_STRING) {
                    $value = substr($text, 1, -1);

                    if (preg_match(self::CONFIG_KEY, $value) === 1) {
                        $methodKeys[] = $value;
                    }

                    continue;
                }

                if (PhpSource::isName($id)
                    && ($body[$j + 1][0] ?? null) === T_DOUBLE_COLON
                    && ($body[$j + 2][0] ?? null) === T_CLASS
                    && ($body[$j + 3][1] ?? '') !== '('
                    && ! in_array(strtolower($text), ['self', 'static', 'parent'], true)) {
                    $class = $source->resolve($text);

                    if (strcasecmp($class, Model::class) !== 0 && PhpSource::inherits($class, Model::class, $parents)) {
                        $models[] = $class;
                    }
                }
            }

            if ($models !== [] && $methodKeys !== []) {
                $call = $seam.'::'.$tokens[$i + 1][1];

                foreach (array_values(array_unique($models)) as $model) {
                    $seams[$model][] = $call;
                    $byCall[strtolower($call)][] = $model;
                }

                array_push($keys, ...$methodKeys);
            }

            $i = $end;
        }
    }

    /**
     * The class constants a source declares with a plain value — one string literal, or one
     * `X::class` — keyed by lower-case name. A seam that keeps its key or its default in a
     * constant (`ModelResolver::for(self::KEY, self::DEFAULT)`) still names both.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     * @return array<string, list<array{0: int|null, 1: string, 2: int}>>
     */
    private static function constants(array $tokens): array
    {
        $constants = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_CONST) {
                continue;
            }

            // `const KEY = …` or, typed, `const string KEY = …`: the name sits right before `=`.
            $equals = $i + 1;

            while ($equals < $count && $tokens[$equals][1] !== '=' && $tokens[$equals][1] !== ';') {
                $equals++;
            }

            $end = $equals;

            while ($end < $count && $tokens[$end][1] !== ';' && $tokens[$end][1] !== ',') {
                $end++;
            }

            $value = array_slice($tokens, $equals + 1, $end - $equals - 1);
            $name = $tokens[$equals - 1] ?? null;
            $plain = (count($value) === 1 && $value[0][0] === T_CONSTANT_ENCAPSED_STRING)
                || (count($value) === 3 && PhpSource::isName($value[0][0]) && $value[1][0] === T_DOUBLE_COLON && $value[2][0] === T_CLASS);

            if (($tokens[$equals][1] ?? '') === '=' && PhpSource::isIdentifier($name) && $plain) {
                $constants[strtolower((string) $name[1])] = $value;
            }

            $i = $end;
        }

        return $constants;
    }

    /**
     * Replace every `self::NAME` / `static::NAME` constant read with the constant's value.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $body
     * @param  array<string, list<array{0: int|null, 1: string, 2: int}>>  $constants
     * @return list<array{0: int|null, 1: string, 2: int}>
     */
    private static function inlineConstants(array $body, array $constants): array
    {
        $inlined = [];
        $count = count($body);

        for ($j = 0; $j < $count; $j++) {
            [$id, $text] = $body[$j];
            $name = $body[$j + 2] ?? null;

            if (($id === T_STATIC || ($id === T_STRING && strtolower($text) === 'self'))
                && ($body[$j + 1][0] ?? null) === T_DOUBLE_COLON
                && $name !== null && isset($constants[strtolower($name[1])])
                && ($body[$j + 3][1] ?? '') !== '(') {
                array_push($inlined, ...$constants[strtolower($name[1])]);
                $j += 2;

                continue;
            }

            $inlined[] = $body[$j];
        }

        return $inlined;
    }

    /**
     * Whether the `function` keyword at `$i` carries a `static` modifier.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     */
    private static function isStatic(array $tokens, int $i): bool
    {
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_ABSTRACT, T_STATIC];

        for ($j = $i - 1; $j >= 0 && in_array($tokens[$j][0], $modifiers, true); $j--) {
            if ($tokens[$j][0] === T_STATIC) {
                return true;
            }
        }

        return false;
    }
}
