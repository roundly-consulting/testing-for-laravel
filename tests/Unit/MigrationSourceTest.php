<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\Migrations\CallArgument;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationSource;

it('removes comments and docblocks but keeps code', function (): void {
    $clean = MigrationSource::withoutComments("<?php\n// \$a->constrained();\n/** ->references( */\n\$b->constrained('x'); # tail\n");

    expect($clean)->not->toContain('$a->constrained')
        ->and($clean)->not->toContain('->references(')
        ->and($clean)->toContain("\$b->constrained('x');");
});

it('reads use imports with and without aliases', function (): void {
    expect(MigrationSource::imports("<?php\nuse App\\Models\\User;\nuse App\\Models\\Team as Squad;\n"))
        ->toBe(['user' => 'App\\Models\\User', 'squad' => 'App\\Models\\Team']);
});

it('splits statements on real semicolons only', function (): void {
    $statements = MigrationSource::statements("\$t->string('a;b')->comment('x'); \$t->id();");

    expect($statements)->toHaveCount(3)
        ->and(implode('', array_column($statements[0], 1)))->toContain("'a;b'");
});

it('captures balanced arguments with named labels and exact text', function (): void {
    $tokens = MigrationSource::statements("\$t->constrained(Registrar::table(Registrar::connection()), column: 'id', indexName: \"fk_x\")")[0];
    $open = null;

    foreach (array_keys($tokens) as $i) {
        $open ??= MigrationSource::methodCallAt($tokens, $i, 'constrained');
    }

    expect($open)->not->toBeNull();

    $arguments = MigrationSource::arguments($tokens, (int) $open);

    expect($arguments)->toEqual([
        new CallArgument(null, 'Registrar::table(Registrar::connection())'),
        new CallArgument('column', "'id'"),
        new CallArgument('indexName', '"fk_x"'),
    ]);
});

it('returns no arguments for an empty call', function (): void {
    $tokens = MigrationSource::statements('$t->constrained( )')[0];

    expect(MigrationSource::arguments($tokens, 3))->toBe([]);
});

it('only recognises a method called through an object operator', function (): void {
    $tokens = MigrationSource::statements('constrained(); Foo::constrained(); $t?->constrained()')[0];

    expect(MigrationSource::methodCallAt($tokens, 0, 'constrained'))->toBeNull();

    $nullsafe = MigrationSource::statements('$t?->constrained()')[0];

    expect(MigrationSource::methodCallAt($nullsafe, 2, 'constrained'))->toBe(3);
});

it('binds named, positional and null arguments like PHP does', function (): void {
    $arguments = [new CallArgument(null, "'users'"), new CallArgument('indexName', "'fk'")];

    expect(CallArgument::bound($arguments, 'table', 0)?->text)->toBe("'users'")
        ->and(CallArgument::bound($arguments, 'column', 1))->toBeNull()
        ->and(CallArgument::bound($arguments, 'indexName', 2)?->text)->toBe("'fk'")
        ->and(CallArgument::bound([new CallArgument(null, 'NULL')], 'table', 0))->toBeNull()
        ->and(CallArgument::bound([new CallArgument('table', 'null')], 'table', 0))->toBeNull();
});
