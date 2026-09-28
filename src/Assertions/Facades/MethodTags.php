<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

/**
 * The `@method` lines of a class docblock, parsed with a **depth-aware scan** rather than a
 * regex.
 *
 * A parameter count is only worth pinning if it cannot miscount, and PHPDoc types are full of
 * commas that do not separate parameters: generics (`array<string, int>`), array shapes
 * (`array{a: int, b: string}`), callable signatures (`Closure(int, string): bool`) and default
 * values (`array $x = ['a' => 1, 'b' => 2]`, `string $glue = ', '`). A comma counts only at
 * depth zero — outside every `<>`, `()`, `[]`, `{}` and quoted string — and a variadic is one
 * parameter. A `>` closes only a `<`, so the `=>` of an array default never unbalances the scan.
 *
 * The method name is the identifier directly before the parameter list; a callable *return*
 * type (`Closure(int): string name()`) is told apart because its closing paren is followed by
 * `:`. A tag spanning several lines is joined before parsing. A tag nothing here can read is
 * kept verbatim in {@see self::$unparseable} — dropping it would let a broken line pass as
 * documentation.
 */
final readonly class MethodTags
{
    /**
     * @param  list<MethodTag>  $tags
     * @param  list<string>  $unparseable
     */
    public function __construct(
        public array $tags,
        public array $unparseable,
    ) {}

    public static function parse(string $docComment): self
    {
        $tags = [];
        $unparseable = [];

        foreach (self::tagLines($docComment) as $line) {
            if (preg_match('/^@method\s+(.*)$/s', $line, $match) !== 1) {
                continue;
            }

            $rest = trim($match[1]);
            $static = preg_match('/^static\s+(?=\S)/', $rest) === 1;

            if ($static) {
                $rest = ltrim(substr($rest, strlen('static')));
            }

            $signature = self::signature($rest);

            if ($signature === null) {
                $unparseable[] = $line;

                continue;
            }

            [$name, $parameters] = $signature;

            $tags[] = new MethodTag($name, self::countParameters($parameters), $static, $line);
        }

        return new self($tags, $unparseable);
    }

    /**
     * Whether the docblock documented nothing at all — not even a line it failed to read.
     */
    public function isEmpty(): bool
    {
        return $this->tags === [] && $this->unparseable === [];
    }

    /**
     * Each `@tag` of the docblock as one line, continuation lines joined on.
     *
     * @return list<string>
     */
    private static function tagLines(string $docComment): array
    {
        $tags = [];
        $current = null;

        foreach (preg_split('/\R/', $docComment) ?: [] as $raw) {
            $line = trim($raw);
            $line = (string) preg_replace('#^/\*\*|\*/$#', '', $line);
            $line = trim(ltrim(trim($line), '*'));

            if (str_starts_with($line, '@')) {
                if ($current !== null) {
                    $tags[] = $current;
                }

                $current = $line;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($line === '') {
                $tags[] = $current;
                $current = null;

                continue;
            }

            $current .= ' '.$line;
        }

        if ($current !== null) {
            $tags[] = $current;
        }

        return $tags;
    }

    /**
     * The method name and the raw text of its parameter list, or null when the tag has no
     * readable `name(...)`.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function signature(string $rest): ?array
    {
        $length = strlen($rest);
        $stack = [];
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $rest[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '(' && $stack === []) {
                $close = self::closingParen($rest, $i);

                if ($close === null) {
                    return null;
                }

                $name = preg_match('/(?<![\\\\\w])([A-Za-z_\x80-\xff][\w\x80-\xff]*)$/', substr($rest, 0, $i), $match) === 1
                    ? $match[1]
                    : null;

                // `Closure(int): string` is a callable return type, not the method.
                $callableType = str_starts_with(ltrim(substr($rest, $close + 1)), ':');

                if ($name !== null && ! $callableType) {
                    return [$name, substr($rest, $i + 1, $close - $i - 1)];
                }

                $i = $close;

                continue;
            }

            self::track($stack, $char);
        }

        return null;
    }

    /**
     * Number of parameters in a documented parameter list: top-level commas split it, and
     * nothing nested inside a type, a default value or a string counts.
     */
    private static function countParameters(string $list): int
    {
        $count = 0;
        $segment = '';
        $stack = [];
        $quote = null;
        $length = strlen($list);

        for ($i = 0; $i < $length; $i++) {
            $char = $list[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $segment .= $char.($list[$i + 1] ?? '');
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                $segment .= $char;

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $segment .= $char;

                continue;
            }

            if ($char === ',' && $stack === []) {
                $count += trim($segment) === '' ? 0 : 1;
                $segment = '';

                continue;
            }

            self::track($stack, $char);
            $segment .= $char;
        }

        return $count + (trim($segment) === '' ? 0 : 1);
    }

    /**
     * Push an opener, pop the matching closer. A closer that does not match the top of the
     * stack is ignored — which is what keeps the `>` of `=>` from closing a `[`.
     *
     * @param  list<string>  $stack
     */
    private static function track(array &$stack, string $char): void
    {
        $pairs = [')' => '(', ']' => '[', '}' => '{', '>' => '<'];

        if (in_array($char, ['(', '[', '{', '<'], true)) {
            $stack[] = $char;

            return;
        }

        if (isset($pairs[$char]) && $stack !== [] && end($stack) === $pairs[$char]) {
            array_pop($stack);
        }
    }

    /**
     * Index of the `)` matching the `(` at $open, quote-aware; null when it never closes.
     */
    private static function closingParen(string $text, int $open): ?int
    {
        $depth = 0;
        $quote = null;
        $length = strlen($text);

        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
