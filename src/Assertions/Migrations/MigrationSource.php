<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

/**
 * Token-level reading of migration source for {@see MigrationGraph}: comments removed,
 * statements split on real `;` tokens, and call arguments captured with balanced nesting.
 *
 * Tokens rather than regexes, for the same reason the config contract uses them: a
 * commented-out `->constrained()` is a `T_COMMENT`, not a key, and it used to count as one —
 * inflating the `foreignKeys:` pin, or failing a correct set over a key nobody declares. And a
 * `;` inside a string literal is not the end of a statement.
 *
 * @internal
 */
final class MigrationSource
{
    /**
     * The source with every comment and docblock removed. Each comment becomes one space, so
     * `a/* x *\/b` cannot fuse two tokens into one.
     */
    public static function withoutComments(string $source): string
    {
        $clean = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $clean .= str_ends_with($token[1], "\n") ? "\n" : ' ';

                continue;
            }

            $clean .= is_array($token) ? $token[1] : $token;
        }

        return $clean;
    }

    /**
     * The source with the body of every method named $name emptied — `function down() {}`.
     *
     * The order pin reads the forward run. A `down()` that re-creates what `up()` dropped is
     * not a CREATE on the way to a migrated schema, and a key inside it is not a key the set
     * declares. Matched by token, case-insensitively (PHP method names are), so a call
     * `$this->down()` or a string 'down' is left alone.
     */
    public static function withoutMethod(string $source, string $name): string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $clean = '';

        for ($i = 0; $i < $count; $i++) {
            $clean .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];

            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $j = $i + 1;

            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if (! isset($tokens[$j]) || ! is_array($tokens[$j]) || strtolower($tokens[$j][1]) !== strtolower($name)) {
                continue;
            }

            // Copy the signature through to the body's `{`, then skip to its matching `}`. A
            // declaration with no body (`;` first) has nothing to empty.
            while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
                $j++;
            }

            if (($tokens[$j] ?? ';') === ';') {
                continue;
            }

            for ($k = $i + 1; $k <= $j && $k < $count; $k++) {
                $clean .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
            }

            $depth = 1;

            for ($j++; $j < $count && $depth > 0; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                $opens = $text === '{' || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));

                $depth += $opens ? 1 : ($text === '}' ? -1 : 0);
            }

            $clean .= '}';
            $i = $j - 1;
        }

        return $clean;
    }

    /**
     * The `use` imports of a file, keyed by lowercased alias — so `use App\Models\Author;` and
     * `use App\Models\Imprint as Publisher;` both resolve a `::class` reference.
     *
     * @return array<string, string>
     */
    public static function imports(string $source): array
    {
        $imports = [];

        preg_match_all('/^\s*use\s+([A-Za-z_][\w\\\\]*)(?:\s+as\s+([A-Za-z_]\w*))?\s*;/m', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $segments = explode('\\', $match[1]);
            $alias = ($match[2] ?? '') !== '' ? $match[2] : (string) end($segments);
            $imports[strtolower($alias)] = ltrim($match[1], '\\');
        }

        return $imports;
    }

    /**
     * Split a source fragment into statements on real `;` tokens, dropping whitespace.
     *
     * @return list<list<array{0: int|null, 1: string}>>
     */
    public static function statements(string $fragment): array
    {
        $tokens = token_get_all('<?php '.$fragment);
        array_shift($tokens);

        $statements = [];
        $current = [];

        foreach ($tokens as $token) {
            $pair = is_array($token) ? [$token[0], $token[1]] : [null, $token];

            if ($pair[1] === ';') {
                $statements[] = $current;
                $current = [];

                continue;
            }

            $current[] = $pair;
        }

        $statements[] = $current;

        return $statements;
    }

    /**
     * The arguments of the call whose `(` is at $open, split on top-level commas, each with
     * its named-argument label. Whitespace tokens are kept inside an argument so its text
     * matches the source exactly (that text is what a `tableResolvers` key names).
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<CallArgument>
     */
    public static function arguments(array $tokens, int $open): array
    {
        $arguments = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];
            $opens = in_array($text, ['(', '[', '{'], true) || in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);

            if ($opens) {
                $depth++;

                if ($depth === 1) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $arguments[] = self::argument($current);
                $current = [];

                continue;
            }

            $current[] = $tokens[$i];
        }

        if (trim(implode('', array_column($current, 1))) !== '') {
            $arguments[] = self::argument($current);
        }

        return $arguments;
    }

    /**
     * Whether the meaningful token at $i is the method `$name` called with `->`/`?->`, i.e.
     * `->constrained(` — returns the index of its `(`, or null.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    public static function methodCallAt(array $tokens, int $i, string $name): ?int
    {
        if ($tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== strtolower($name)) {
            return null;
        }

        $previous = self::neighbour($tokens, $i, -1);
        $next = self::neighbour($tokens, $i, 1);

        if ($previous === null || ! in_array($tokens[$previous][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return null;
        }

        return $next !== null && $tokens[$next][1] === '(' ? $next : null;
    }

    /**
     * The index of the nearest non-whitespace token in $direction (-1 / +1), or null.
     *
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function neighbour(array $tokens, int $i, int $direction): ?int
    {
        for ($j = $i + $direction; isset($tokens[$j]); $j += $direction) {
            if ($tokens[$j][0] !== T_WHITESPACE) {
                return $j;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function argument(array $tokens): CallArgument
    {
        $meaningful = array_values(array_filter($tokens, static fn (array $t): bool => $t[0] !== T_WHITESPACE));

        // `table: 'users'` — a label is a bare identifier followed by a lone `:` token (the
        // `::` of a static call is a single T_DOUBLE_COLON token, so it never matches).
        if (count($meaningful) >= 3 && $meaningful[0][0] === T_STRING && $meaningful[1][1] === ':') {
            $name = $meaningful[0][1];
            $colon = array_search($meaningful[1], $tokens, true);
            $rest = array_slice($tokens, (int) $colon + 1);

            return new CallArgument($name, trim(implode('', array_column($rest, 1))));
        }

        return new CallArgument(null, trim(implode('', array_column($tokens, 1))));
    }
}
