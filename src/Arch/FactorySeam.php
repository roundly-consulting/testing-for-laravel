<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Illuminate\Database\Eloquent\Factories\Factory;
use PHPUnit\Framework\Assert;

/**
 * Every factory of a swappable model builds the model the host **configured**, not the one the
 * package ships: it overrides `modelName()` to return through the seam.
 *
 * ## The bug this pins
 *
 * A model factory builds `$model` — the packaged class — unless `modelName()` says otherwise.
 * `Comment::factory()` therefore creates a packaged `Comment` even when the host has swapped it
 * for `TenantComment` on its own table: the row lands in the wrong table, without the host's
 * casts, scopes, events or observers, and a host test seeding through the factory reads empty
 * through every seam-resolved query. The fix is one method, the likes/posts/reviews pattern:
 *
 * ```php
 * protected $model = Like::class;
 *
 * public function modelName(): string
 * {
 *     return LikeModel::class();
 * }
 * ```
 *
 * ## What is checked
 *
 * The swappable models are the package's own, read from its seam exactly as
 * {@see ModelSeam} reads them ({@see SeamModels}): every model a static, parameterless,
 * string-returning method under `$seamDir` names beside a config key.
 *
 * A factory is any concrete class under `$factoriesDir` extending Eloquent's {@see Factory}
 * (directly, or through a base factory in the same directory; an abstract base is not one). Its model is its `protected $model =
 * X::class` default. A factory of a swappable model must declare `modelName()` whose body is
 * exactly `return Seam::method();`, where `Seam::method` is a seam method resolving **that**
 * model — a seam for another model, `return $this->model`, or a missing override all fail. A
 * factory that declares no `$model` but returns a seam is read as building that seam's model.
 *
 * Everything is read from source tokens ({@see PhpSource}), so a docblock showing the pattern
 * does not satisfy it.
 *
 * ## Non-vacuous
 *
 * A missing directory fails, and so do: a package whose seam names no swappable model, a
 * factories directory with no factory class, and factories none of which builds a swappable
 * model. Each of those would pass over nothing. A factory whose model cannot be read fails too,
 * rather than being skipped.
 *
 * ## Opt-in, on purpose
 *
 * This is not folded into {@see ArchPresets::modelsResolveThroughSeam()}, which every package
 * adopts automatically: 21 packages shipped factories without the override when this landed, and
 * folding it in would have turned all of them red on their next run. Each adopts it with its fix.
 *
 * This is the assertion behind {@see ArchPresets::factoriesResolveThroughSeam()}.
 */
final class FactorySeam
{
    public static function assert(string $factoriesDir, string $srcDir, string $seamDir = 'Support'): void
    {
        Assert::assertDirectoryExists($factoriesDir, "Factories directory does not exist: {$factoriesDir}");
        Assert::assertDirectoryExists($srcDir, "Source directory does not exist: {$srcDir}");

        $models = SeamModels::discover(PhpSource::allIn($srcDir), $srcDir, $seamDir);

        Assert::assertNotSame(
            [],
            $models->models(),
            "No swappable model was found in the '{$seamDir}' seam under {$srcDir}: no static, parameterless "
            .'method returning string names an Eloquent model beside a config key. A package with no '
            .'swappable model must not adopt factoriesResolveThroughSeam().',
        );

        $factories = self::factories($factoriesDir);

        Assert::assertNotSame(
            [],
            $factories,
            "No Eloquent factory class was found under {$factoriesDir}, so this pin would pass over nothing. "
            .'Point it at the directory that holds the factories.',
        );

        $checked = 0;
        $violations = [];

        foreach ($factories as $file => $source) {
            $name = SeamModels::relative($factoriesDir, $file);
            $declared = self::declaredModel($source);
            $returned = self::modelNameReturn($source);
            $resolves = $returned === null ? [] : $models->resolvedBy($returned);

            if ($declared === null && count($resolves) !== 1) {
                $violations[] = "{$name}: cannot tell which model it builds — declare `protected \$model = Model::class;`";

                continue;
            }

            $model = $declared ?? $resolves[0];

            if (! $models->isSwappable($model)) {
                continue;
            }

            $checked++;

            if (self::matches($model, $resolves)) {
                continue;
            }

            $seams = implode(' or ', array_map(
                static fn (string $seam): string => self::shortName($seam).'()',
                $models->seamsFor($model),
            ));

            $violations[] = $returned === null
                ? "{$name}: builds ".self::shortName($model)." but does not return its seam from modelName() — return {$seams}"
                : "{$name}: builds ".self::shortName($model).' but modelName() returns '
                    .self::shortName($returned)."() — return {$seams}";
        }

        sort($violations);

        Assert::assertSame(
            [],
            $violations,
            "These factories build a swappable model's packaged class instead of the one the host "
            ."configured:\n  - ".implode("\n  - ", $violations)
            ."\nOverride modelName() to return the model's seam, so a swapped model gets its own rows.",
        );

        Assert::assertGreaterThan(
            0,
            $checked,
            'None of the '.count($factories)." factories under {$factoriesDir} builds a swappable model ("
            .implode(', ', array_map(self::shortName(...), $models->models())).'), so this pin checks '
            .'nothing. A package whose factories build no swappable model must not adopt it.',
        );
    }

    /**
     * Every class under `$dir` that extends Eloquent's Factory, keyed by path.
     *
     * @return array<string, PhpSource>
     */
    private static function factories(string $dir): array
    {
        $sources = PhpSource::allIn($dir);
        $parents = SeamModels::parents($sources);
        $factories = [];

        foreach ($sources as $file => $source) {
            if ($source->kind === T_CLASS
                && ! $source->abstract
                && $source->class !== null
                && $source->parent !== null
                && PhpSource::inherits($source->parent, Factory::class, $parents)) {
                $factories[$file] = $source;
            }
        }

        return $factories;
    }

    /**
     * The class a `protected $model = X::class;` property default names, resolved — or null.
     */
    private static function declaredModel(PhpSource $source): ?string
    {
        $tokens = $source->tokens;
        $count = count($tokens);
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR];

        for ($i = 1; $i < $count; $i++) {
            if ($tokens[$i][0] === T_VARIABLE
                && $tokens[$i][1] === '$model'
                && in_array($tokens[$i - 1][0], $modifiers, true)
                && ($tokens[$i + 1][1] ?? '') === '='
                && PhpSource::isName($tokens[$i + 2][0] ?? null)
                && ($tokens[$i + 3][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i + 4][0] ?? null) === T_CLASS
                && ($tokens[$i + 5][1] ?? '') === ';') {
                return $source->resolve($tokens[$i + 2][1]);
            }
        }

        return null;
    }

    /**
     * The `Fq\Class::method` static call `modelName()` returns when its whole body is
     * `return Class::method();` — or null for a missing override or any other body.
     */
    private static function modelNameReturn(PhpSource $source): ?string
    {
        $tokens = $source->tokens;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION
                || ! PhpSource::isIdentifier($tokens[$i + 1] ?? null)
                || strcasecmp($tokens[$i + 1][1], 'modelName') !== 0) {
                continue;
            }

            $open = $i + 2;

            while ($open < $count && $tokens[$open][1] !== '{' && $tokens[$open][1] !== ';') {
                $open++;
            }

            if ($open >= $count || $tokens[$open][1] !== '{') {
                return null;
            }

            $body = array_slice($tokens, $open + 1, PhpSource::closing($tokens, $open) - $open - 1);

            if (count($body) !== 7
                || $body[0][0] !== T_RETURN
                || ! PhpSource::isName($body[1][0])
                || $body[2][0] !== T_DOUBLE_COLON
                || ! PhpSource::isIdentifier($body[3])
                || $body[4][1] !== '('
                || $body[5][1] !== ')'
                || $body[6][1] !== ';') {
                return null;
            }

            return $source->resolve($body[1][1]).'::'.$body[3][1];
        }

        return null;
    }

    /**
     * @param  list<string>  $resolves
     */
    private static function matches(string $model, array $resolves): bool
    {
        foreach ($resolves as $resolved) {
            if (strcasecmp($resolved, $model) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function shortName(string $name): string
    {
        $separator = strrpos($name, '\\');

        return $separator === false ? $name : substr($name, $separator + 1);
    }
}
