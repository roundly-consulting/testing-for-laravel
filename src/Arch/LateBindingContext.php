<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

/**
 * Tracks, token by token, whether the scan is inside a **static** function body.
 *
 * {@see ModelSeam} needs this to tell a seam bypass from the identical-looking token shape
 * that is correct. `self::query()` in a static method may resolve to the class the caller
 * named; the same call in an instance method resolves through `$this` to the configured
 * class. Only the first is a bypass, and the difference is not visible in the tokens around
 * the call — only in what encloses it.
 *
 * Nesting taints outward: any enclosing static function makes the whole nesting static,
 * because a closure declared inside a static method has no `$this` either. That keeps the
 * error on the false-negative side, which is where an unappealable `it()` case belongs.
 *
 * @internal
 */
final class LateBindingContext
{
    /**
     * One frame per open function body: true when that function is static.
     *
     * @var list<array{static: bool, depth: int}>
     */
    private array $frames = [];

    private int $depth = 0;

    /**
     * True when a static function encloses the current token — or when no function does at
     * all, since a bare late-static-binding token outside every method has no `$this` to pin
     * the called class either.
     */
    private bool $pendingStatic = false;

    private bool $awaitingBody = false;

    /**
     * Advance the context past the token at $i.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    public function observe(array $tokens, int $i): void
    {
        [$id, $text] = $tokens[$i];

        if ($id === T_FUNCTION) {
            $this->awaitingBody = true;
            $this->pendingStatic = self::isStaticFunction($tokens, $i);

            return;
        }

        // An abstract or interface method, or a `static fn() => …` arrow function: no braced
        // body follows, so the pending declaration never opens a frame.
        if ($this->awaitingBody && $id === null && $text === ';') {
            $this->awaitingBody = false;

            return;
        }

        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $this->depth++;

            // Only a plain brace opens a function body — the `{` of a `"{$interpolation}"`
            // is counted for balance but never mistaken for one.
            if ($this->awaitingBody && $id === null) {
                $this->frames[] = ['static' => $this->pendingStatic, 'depth' => $this->depth];
                $this->awaitingBody = false;
            }

            return;
        }

        if ($text === '}') {
            $top = end($this->frames);

            if ($top !== false && $top['depth'] === $this->depth) {
                array_pop($this->frames);
            }

            $this->depth--;
        }
    }

    public function inStaticContext(): bool
    {
        if ($this->frames === []) {
            return true;
        }

        foreach ($this->frames as $frame) {
            if ($frame['static']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the `function` keyword at $i carries a `static` modifier.
     *
     * Scans back over the modifier run only — `public static function`, `final static
     * public function`, and a `static function () {}` closure all land on true, while a
     * `public function foo(): static` return type is never in that run and lands on false.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function isStaticFunction(array $tokens, int $i): bool
    {
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_ABSTRACT, T_READONLY, T_STATIC];

        for ($j = $i - 1; $j >= 0; $j--) {
            [$id] = $tokens[$j];

            if ($id === T_STATIC) {
                return true;
            }

            if (! in_array($id, $modifiers, true)) {
                return false;
            }
        }

        return false;
    }
}
