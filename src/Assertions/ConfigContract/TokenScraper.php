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
 *   - a `$var['key']` array-offset read where `$var` is mapped to a base path via
 *     `sectionVariables` (a package that hands its whole config array to a DTO reads
 *     keys by offset, not through `config()`);
 *   - any literal string under one of `extraReadPrefixes`, wherever it appears
 *     (e.g. `ModelResolver::for('pkg.model')` — not a `config()` call).
 *
 * A `config("pkg.{$x}")` interpolation or `config('pkg.'.$x)` concatenation under the
 * prefix is never silently treated as "reads nothing"; it is collected as an
 * interpolation so the contract can fail and demand a literal key or an allow-list.
 */
final class TokenScraper
{
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

        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            if ($this->opensConfigCall($tokens, $i)) {
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

        if ($id === T_STRING && $text === 'get') {
            $prev = $tokens[$i - 1] ?? null;
            $prevPrev = $tokens[$i - 2] ?? null;

            return $next !== null && $next[1] === '('
                && $prev !== null && $prev[0] === T_DOUBLE_COLON
                && $prevPrev !== null && $prevPrev[0] === T_STRING && $prevPrev[1] === 'Config';
        }

        return false;
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
     * If the variable at $i is immediately indexed by a string literal (`$var['key']`),
     * return that key; otherwise null.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private function offsetRead(array $tokens, int $i): ?string
    {
        $open = $tokens[$i + 1] ?? null;
        $key = $tokens[$i + 2] ?? null;
        $close = $tokens[$i + 3] ?? null;

        if ($open !== null && $open[1] === '['
            && $key !== null && $key[0] === T_CONSTANT_ENCAPSED_STRING
            && $close !== null && $close[1] === ']') {
            return $this->stringValue($key[1]);
        }

        return null;
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
