<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\Facades\DeclaredClass;
use RoundlyConsulting\Testing\Assertions\Facades\DeclaredClasses;
use RoundlyConsulting\Testing\Assertions\Facades\InternalTag;
use RoundlyConsulting\Testing\Assertions\Facades\SourceReferences;

// ---------------------------------------------------------------------------
// SourceReferences — names resolved the way PHP resolves them, code only.
// ---------------------------------------------------------------------------

it('resolves imports, aliases, group imports and every name form', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Teams;

        use App\Teams\Actions\CreateTeam;
        use App\Teams\Actions\RenameTeam as Rename;
        use App\Teams\Actions\{Members\AddMember, RemoveMember as Remove, function helper, const LIMIT};
        use App\Teams\Actions as Verbs;
        use function App\Teams\format;
        use const App\Teams\MAX;

        final class Manager
        {
            public function run(): void
            {
                app(CreateTeam::class);
                app(Rename::class);
                app(AddMember::class);
                app(Remove::class);
                app(Verbs\ArchiveTeam::class);
                app(\App\Teams\Actions\PruneTeams::class);
                app(namespace\Local::class);
                app(Sibling::class);
            }
        }
        PHP;

    expect(SourceReferences::inSource($source))->toContain(
        'App\Teams\Actions\CreateTeam',
        'App\Teams\Actions\RenameTeam',
        'App\Teams\Actions\Members\AddMember',
        'App\Teams\Actions\RemoveMember',
        'App\Teams\Actions\ArchiveTeam',
        'App\Teams\Actions\PruneTeams',
        'App\Teams\Local',
        'App\Teams\Sibling',
    );
});

it('ignores comments, docblocks, strings and member names', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App;

        use App\Actions\Documented;

        /**
         * @see \App\Actions\InDocblock
         */
        final class Manager
        {
            public function run(): void
            {
                // app(\App\Actions\InComment::class);
                app('App\Actions\InString');
                $this->Member();
                self::StaticMember();
            }

            public function Declared(): void {}
        }
        PHP;

    $references = SourceReferences::inSource($source);

    expect($references)
        ->not->toContain('App\Actions\InDocblock')
        ->not->toContain('App\Actions\InComment')
        ->not->toContain('App\Actions\InString')
        ->not->toContain('App\Member')
        ->not->toContain('App\StaticMember')
        ->not->toContain('App\Declared')
        // An unused import is not a use of the name…
        ->not->toContain('App\Actions\Documented');

    // …unless the caller asks for imports too.
    expect(SourceReferences::inSource($source, withImports: true))->toContain('App\Actions\Documented');
});

it('treats a use inside a class body as a trait reference and a closure use as variables', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App;

        use App\Concerns\Loud;

        final class Manager
        {
            use Loud, Quiet;

            public function run(): \Closure
            {
                $x = 1;

                return function () use ($x) { return new Built("{$x}"); };
            }
        }

        $top = function () use ($top) { return Scripted::class; };
        PHP;

    expect(SourceReferences::inSource($source))->toContain('App\Concerns\Loud', 'App\Quiet', 'App\Built', 'App\Scripted', 'Closure');
});

it('reads a trait used by an anonymous class', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App;

        use App\Concerns\Loud;

        return new class { use Loud; };
        PHP;

    expect(SourceReferences::inSource($source))->toContain('App\Concerns\Loud');
});

it('survives truncated source without inventing declarations', function (): void {
    expect(DeclaredClasses::inSource('<?php final class'))->toBe([])
        ->and(DeclaredClasses::inSource('<?php #[Attribute(1'))->toBe([]);
});

it('resets imports per braced namespace', function (): void {
    $source = <<<'PHP'
        <?php

        namespace First {
            use Vendor\Thing;

            final class A { public function a(): Thing {} }
        }

        namespace Second {
            final class B { public function b(): Thing {} }
        }

        namespace {
            final class C { public function c(): Thing {} }
        }
        PHP;

    expect(SourceReferences::inSource($source))->toContain('Vendor\Thing', 'Second\Thing', 'Thing')
        ->not->toContain('First\Thing');
});

it('reads a file from disk', function (): void {
    $file = fixturePath('Facades/Teams/MembersAccessor.php');

    expect(SourceReferences::inFile($file))
        ->toContain('RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Members\AddMember');
});

// ---------------------------------------------------------------------------
// DeclaredClasses — declarations from tokens, not the autoloader.
// ---------------------------------------------------------------------------

it('reads every kind of declaration with its abstract and internal flags', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Actions;

        /**
         * @internal
         */
        #[SomeAttribute(['a' => [1, 2]])]
        final readonly class Internal {}

        abstract class Base {}

        final class Concrete
        {
            public function make(): object
            {
                return new class {};
            }

            public function name(): string
            {
                return self::class;
            }
        }

        interface Contract {}

        trait Helper {}

        enum State {}

        /** A docblock that belongs to nothing. */
        $x = 1;
        final class AfterStatement {}
        PHP;

    $declared = array_map(
        fn (DeclaredClass $class): string => "{$class->kind}:{$class->name}:".($class->abstract ? 'abstract' : '-').':'.($class->internal ? 'internal' : '-'),
        DeclaredClasses::inSource($source),
    );

    expect($declared)->toBe([
        'class:App\Actions\Internal:-:internal',
        'class:App\Actions\Base:abstract:-',
        'class:App\Actions\Concrete:-:-',
        'interface:App\Actions\Contract:-:-',
        'trait:App\Actions\Helper:-:-',
        'enum:App\Actions\State:-:-',
        'class:App\Actions\AfterStatement:-:-',
    ]);
});

it('only calls a concrete, non-internal class host-facing', function (): void {
    $host = fn (string $kind, bool $abstract, bool $internal): bool => (new DeclaredClass('X', '', $kind, $abstract, $internal))->isHostFacing();

    expect($host('class', false, false))->toBeTrue()
        ->and($host('class', true, false))->toBeFalse()
        ->and($host('class', false, true))->toBeFalse()
        ->and($host('interface', false, false))->toBeFalse()
        ->and($host('trait', false, false))->toBeFalse();
});

it('collects the declarations of a whole directory, and nothing from a missing one', function (): void {
    $names = array_map(fn (DeclaredClass $class): string => $class->name, DeclaredClasses::in(fixturePath('Facades/Ledger/Actions')));

    expect($names)->toBe([
        'RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Actions\ComputeBalance',
        'RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Actions\RecordEntry',
    ])->and(DeclaredClasses::in(fixturePath('Facades/does-not-exist')))->toBe([]);
});

it('reads @internal only as a tag, never from prose', function (string $docblock, bool $internal): void {
    expect(InternalTag::in($docblock))->toBe($internal);
})->with([
    'tag line' => ["/**\n * A building block.\n *\n * @internal\n */", true],
    'tag with text' => ["/**\n * @internal wiring only\n */", true],
    'single line' => ['/** @internal */', true],
    'inline' => ["/**\n * {@internal Only for the provider.}\n */", true],
    'prose mention' => ["/**\n * Composed by another action, but not marked @internal — so host-facing.\n */", false],
    'no tag' => ["/**\n * @see Other\n */", false],
]);
