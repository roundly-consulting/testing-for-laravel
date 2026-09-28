<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\Facades\MethodTag;
use RoundlyConsulting\Testing\Assertions\Facades\MethodTags;

/**
 * @return array<string, int>
 */
function documentedCounts(string $docblock): array
{
    $counts = [];

    foreach (MethodTags::parse($docblock)->tags as $tag) {
        $counts[$tag->name] = $tag->parameters;
    }

    return $counts;
}

it('counts parameters without being fooled by generics, shapes, callables, defaults or quotes', function (): void {
    $docblock = <<<'DOC'
        /**
         * @method static int generics(array<string, int> $map, \Illuminate\Support\Collection<int, string> $items)
         * @method static int shape(array{a: int, b: string} $shape)
         * @method static int callable(Closure(int, string): bool $resolver, callable(int, int): void $each)
         * @method static int defaults(array $options = ['a' => 1, 'b' => [2, 3]], string $glue = ', ')
         * @method static int escaped(string $quote = 'it\'s, fine', string $other = "a, \"b\"")
         * @method static int variadic(string $first, string ...$rest)
         * @method static void none()
         */
        DOC;

    expect(documentedCounts($docblock))->toBe([
        'generics' => 2,
        'shape' => 1,
        'callable' => 2,
        'defaults' => 2,
        'escaped' => 2,
        'variadic' => 2,
        'none' => 0,
    ]);
});

it('finds the method name past a callable or conditional return type', function (): void {
    $docblock = <<<'DOC'
        /**
         * @method static Closure(int, string): bool resolver(int $a, int $b, int $c)
         * @method static \Closure(int): void fqcnCallable(int $a)
         * @method static ($x is string ? int : bool) conditional(mixed $x)
         * @method static static fresh()
         * @method static noReturnType(int $a, int $b)
         * @method static TeamHandle for(Team $team)
         */
        DOC;

    expect(documentedCounts($docblock))->toBe([
        'resolver' => 3,
        'fqcnCallable' => 1,
        'conditional' => 1,
        'fresh' => 0,
        'noReturnType' => 2,
        'for' => 1,
    ]);
});

it('reads past quoted literal types, escapes included', function (): void {
    $docblock = <<<'DOC'
        /**
         * @method static 'open'|'it\'s (closed)'|"x(y)" mode(string $a, string $b = 'a, (b)')
         */
        DOC;

    expect(documentedCounts($docblock))->toBe(['mode' => 2]);
});

it('joins a tag spanning several lines before counting', function (): void {
    $docblock = <<<'DOC'
        /**
         * @method static int sync(
         *     array<string, int> $map,
         *     Closure(int, string): bool $resolver,
         * )
         *
         * @see Somewhere
         */
        DOC;

    expect(documentedCounts($docblock))->toBe(['sync' => 2]);
});

it('records whether a tag was written static', function (): void {
    $tags = MethodTags::parse("/**\n * @method static void a()\n * @method void b()\n */")->tags;

    expect(array_map(fn (MethodTag $tag): bool => $tag->static, $tags))->toBe([true, false]);
});

it('reads a single-line docblock', function (): void {
    expect(documentedCounts('/** @method static void only(int $a) */'))->toBe(['only' => 1]);
});

it('keeps a tag it cannot read instead of dropping it', function (): void {
    $parsed = MethodTags::parse("/**\n * @method static int\n * @method static int broken(int \$a\n * @method static void ok()\n */");

    expect($parsed->unparseable)->toBe(['@method static int', '@method static int broken(int $a'])
        ->and(array_map(fn (MethodTag $tag): string => $tag->name, $parsed->tags))->toBe(['ok'])
        ->and($parsed->isEmpty())->toBeFalse();
});

it('is empty for a docblock without @method tags', function (): void {
    expect(MethodTags::parse("/**\n * Just prose.\n *\n * @see Foo\n */")->isEmpty())->toBeTrue()
        ->and(MethodTags::parse('')->isEmpty())->toBeTrue();
});
