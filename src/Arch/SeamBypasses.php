<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

/**
 * The two ways code outside the seam names a swappable model's **packaged** class, read from
 * source tokens. Both build or relate the class the package ships, never the subclass the host
 * configured:
 *
 *  - a relation whose argument is the model's literal class — `belongsTo(Comment::class)`,
 *    `$this->morphMany(Comment::class, 'commentable')`, or `self::class` inside the swappable
 *    model itself, which is the same literal spelled differently. A host that swaps `Comment`
 *    gets the packaged class back from every such relation;
 *  - a static call on the model that starts a query (`Comment::query()`, `Comment::where()`,
 *    `Comment::withTrashed()`, …) — see {@see QueryForwarding}. It queries the packaged class,
 *    so the host subclass's scopes, casts and events never apply.
 *
 * `Comment::class` on its own is not a bypass (`instanceof`, `is_a()`, a morph map): the
 * configured class is always `Comment` or a subclass, so a check against the base is correct.
 * Docblocks, comments and string literals are not code ({@see PhpSource}).
 *
 * @internal
 */
final class SeamBypasses
{
    /**
     * The relation builders whose arguments name a related (or intermediate) model. Lowercased.
     *
     * @var list<string>
     */
    public const array RELATIONS = [
        'belongsto', 'hasmany', 'hasone', 'morphmany', 'morphone', 'belongstomany',
        'hasmanythrough', 'hasonethrough', 'morphtomany', 'morphedbymany',
    ];

    /**
     * `file:line relation(Model::class)` for every relation outside the seam that names a
     * swappable model by its literal class in any argument.
     *
     * @param  array<string, PhpSource>  $sources
     * @return list<string>
     */
    public static function literalRelations(array $sources, string $srcDir, string $seamDir, SeamModels $models): array
    {
        $found = [];

        foreach ($sources as $file => $source) {
            if (SeamModels::inSeam($srcDir, $file, $seamDir)) {
                continue;
            }

            $tokens = $source->tokens;
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $method = $tokens[$i + 1] ?? null;

                if (! in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                    || $method === null || $method[0] !== T_STRING
                    || ! in_array(strtolower($method[1]), self::RELATIONS, true)
                    || ($tokens[$i + 2][1] ?? '') !== '(') {
                    continue;
                }

                foreach (self::arguments($tokens, $i + 2) as $argument) {
                    $model = self::literalModel($argument, $source, $models);

                    if ($model !== null) {
                        $found[] = SeamModels::relative($srcDir, $file).':'.$method[2]
                            .' '.$method[1].'('.self::shortName($model).'::class)';
                    }
                }
            }
        }

        sort($found, SORT_NATURAL);

        return array_values(array_unique($found));
    }

    /**
     * `file:line Model::method()` for every static call outside the seam that starts a query on
     * a swappable model's packaged class.
     *
     * @param  array<string, PhpSource>  $sources
     * @return list<string>
     */
    public static function staticEntryPoints(array $sources, string $srcDir, string $seamDir, SeamModels $models): array
    {
        $found = [];

        foreach ($sources as $file => $source) {
            if (SeamModels::inSeam($srcDir, $file, $seamDir)) {
                continue;
            }

            $tokens = $source->tokens;
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                [$id, $text, $line] = $tokens[$i];
                $method = $tokens[$i + 2] ?? null;

                if ($method === null
                    || ! PhpSource::isName($id)
                    || in_array(strtolower($text), ['self', 'parent'], true)
                    || ($tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON
                    || ! PhpSource::isIdentifier($method)
                    || ($tokens[$i + 3][1] ?? '') !== '('
                    || in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    continue;
                }

                $model = $models->model($source->resolve($text));

                if ($model !== null && QueryForwarding::startsQuery($model, $method[1])) {
                    $found[] = SeamModels::relative($srcDir, $file).':'.$line
                        .' '.self::shortName($model).'::'.$method[1].'()';
                }
            }
        }

        sort($found, SORT_NATURAL);

        return array_values(array_unique($found));
    }

    /**
     * The swappable model an argument names as `X::class` (or `self::class` inside that model),
     * or null. A named argument's label (`related:`) is skipped.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $argument
     */
    private static function literalModel(array $argument, PhpSource $source, SeamModels $models): ?string
    {
        if (count($argument) === 5 && $argument[0][0] === T_STRING && $argument[1][1] === ':') {
            $argument = array_slice($argument, 2);
        }

        if (count($argument) !== 3
            || ! PhpSource::isName($argument[0][0])
            || $argument[1][0] !== T_DOUBLE_COLON
            || $argument[2][0] !== T_CLASS) {
            return null;
        }

        $name = $argument[0][1];

        if (strtolower($name) === 'self') {
            return $source->kind === T_CLASS && $source->class !== null ? $models->model($source->class) : null;
        }

        if (strtolower($name) === 'parent') {
            return $source->parent === null ? null : $models->model($source->parent);
        }

        return $models->model($source->resolve($name));
    }

    /**
     * The top-level arguments of the call whose `(` is at `$open`, each as its tokens.
     *
     * @param  list<array{0: int|null, 1: string, 2: int}>  $tokens
     * @return list<list<array{0: int|null, 1: string, 2: int}>>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $close = PhpSource::closing($tokens, $open);
        $arguments = [];
        $current = [];
        $depth = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            [$id, $text] = $tokens[$i];

            if ($id === null && in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === null && in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $id === null && $text === ',') {
                $arguments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $tokens[$i];
        }

        if ($current !== []) {
            $arguments[] = $current;
        }

        return $arguments;
    }

    private static function shortName(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
