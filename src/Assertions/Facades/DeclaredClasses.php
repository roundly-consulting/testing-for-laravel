<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use RoundlyConsulting\Testing\Support\PhpFiles;

/**
 * Class-like declarations read from **source tokens**, never from the autoloader.
 *
 * Collecting actions by tokens means a file that is not autoloadable (a typo'd namespace, a
 * class Composer does not map) is still counted — so it cannot drop out of the reachability
 * check silently. Anonymous classes and `Foo::class` constants are not declarations: both
 * are told apart by the `class` keyword not being followed by a name.
 */
final class DeclaredClasses
{
    private const array KINDS = [
        T_CLASS => 'class',
        T_INTERFACE => 'interface',
        T_TRAIT => 'trait',
        T_ENUM => 'enum',
    ];

    /**
     * @return list<DeclaredClass>
     */
    public static function in(string $dir): array
    {
        $declared = [];

        foreach (PhpFiles::in($dir) as $file) {
            foreach (self::inSource((string) file_get_contents($file), $file) as $class) {
                $declared[] = $class;
            }
        }

        return $declared;
    }

    /**
     * @return list<DeclaredClass>
     */
    public static function inSource(string $source, string $file = ''): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);

        $namespace = '';
        $doc = null;
        $abstract = false;
        $declared = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = is_array($token) ? $token[0] : null;

            if ($id === T_WHITESPACE || $id === T_COMMENT) {
                continue;
            }

            if ($id === T_DOC_COMMENT) {
                $doc = $token[1];

                continue;
            }

            if ($id === T_ATTRIBUTE) {
                $i = self::endOfAttribute($tokens, $i);

                continue;
            }

            if ($id === T_ABSTRACT || $id === T_FINAL || $id === T_READONLY) {
                $abstract = $abstract || $id === T_ABSTRACT;

                continue;
            }

            if ($id === T_NAMESPACE) {
                $next = self::nextSignificant($tokens, $i);

                if ($next !== null && is_array($tokens[$next]) && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $tokens[$next][1];
                    $i = $next;
                }
            } elseif ($id !== null && isset(self::KINDS[$id])) {
                $next = self::nextSignificant($tokens, $i);

                // `Foo::class` and `new class` are followed by something other than a name.
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $declared[] = new DeclaredClass(
                        name: $namespace === '' ? $tokens[$next][1] : $namespace.'\\'.$tokens[$next][1],
                        file: $file,
                        kind: self::KINDS[$id],
                        abstract: $abstract,
                        internal: $doc !== null && InternalTag::in($doc),
                    );

                    $i = $next;
                }
            }

            $doc = null;
            $abstract = false;
        }

        return $declared;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function nextSignificant(array $tokens, int $i): ?int
    {
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $j;
        }

        return null;
    }

    /**
     * Index of the `]` closing the `#[` attribute opened at $i.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function endOfAttribute(array $tokens, int $i): int
    {
        $depth = 1;
        $count = count($tokens);

        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j] === '[') {
                $depth++;
            } elseif ($tokens[$j] === ']' && --$depth === 0) {
                return $j;
            }
        }

        return $count;
    }
}
