<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

use PHPUnit\Framework\Assert;

/**
 * Parses a directory of migration source files and pins that their directory-sort
 * order is *runnable* from an empty database: every foreign-key target is created
 * before the table that references it, and every ALTER sorts at or after its CREATE.
 *
 * Why parse the source instead of migrating and looking? SQLite happily creates a
 * table that references a missing parent — it only complains at insert time — so a
 * broken order stays green under a normal SQLite suite. Five fleet packages shipped
 * uninstallable orders that way. This check is therefore *structural*: it reads the
 * foreign keys straight out of the source and asserts the ordering, which is
 * engine-independent and fails on SQLite the moment an order breaks.
 *
 * ## Order is per Schema block, not per file
 *
 * Every `Schema::create()`/`Schema::table()` block gets an ordinal in run order — files in
 * directory order, blocks in source order within a file. A file is not one instant: an app
 * that creates `teams` and then `team_members` in one migration has a runnable order, and
 * the same two blocks the other way round do not. Comparing file indices called both
 * broken.
 *
 * ## A table's life, read from `up()` only
 *
 * A table is not created once and for all: a forward-only migration may `Schema::drop()` /
 * `Schema::dropIfExists()` it and create it again. Each table therefore carries its
 * *lifetimes* — create ordinal to drop ordinal — and every ALTER and key is checked against
 * the lifetime live **at its own ordinal**, not against whichever CREATE came last. A second
 * CREATE while the first is still live fails: every engine refuses it.
 *
 * Only the forward run counts. `down()` is removed before parsing, so a rollback that
 * re-creates a table the same migration's `up()` dropped is not mistaken for a CREATE.
 *
 * ## Tables the set does not own
 *
 * `$externalTables` names tables that exist before this set runs — the host app's `users`,
 * a vendor's table. A key onto one, or an ALTER of one, is then satisfied without a CREATE in
 * the set. Each entry is rot-checked: it must be referenced by some key or ALTER, and the set
 * must not create it.
 *
 * Guard-the-guard: the number of foreign keys parsed must equal the raw
 * `->constrained(` / `->references(` occurrence count in each file (comments excluded). An
 * unparseable declaration fails the assertion — it is never silently dropped, because a
 * pin that quietly parses nothing passes vacuously.
 */
final class MigrationGraph
{
    /** @var array<string, list<array{from: int, to: int|null}>> table name => its lifetimes, in run order */
    private array $lifetimes = [];

    /** @var list<ForeignKeyEdge> */
    private array $edges = [];

    /** @var list<array{table: string, at: int}> */
    private array $alters = [];

    /**
     * @param  list<string>  $externalTables  tables that exist before the set runs
     */
    public function __construct(
        private readonly TableResolver $resolver,
        private readonly array $externalTables = [],
    ) {}

    /**
     * @param  array<string, string>  $tableResolvers
     * @param  list<string>  $externalTables
     */
    public static function forDirectory(string $directory, array $tableResolvers = [], array $externalTables = []): self
    {
        $graph = new self(new TableResolver($tableResolvers), $externalTables);
        $graph->parse($directory);

        return $graph;
    }

    public function assertRunnable(?int $expectedForeignKeys): void
    {
        foreach ($this->externalTables as $external) {
            Assert::assertArrayNotHasKey(
                $external,
                $this->lifetimes,
                "`{$external}` is declared in externalTables, but this migration set creates it. An external "
                .'table exists before the set runs; one the set creates is ordered like any other. Remove it.',
            );
        }

        foreach ($this->alters as $alter) {
            if ($this->isExternal($alter['table'])) {
                continue;
            }

            Assert::assertArrayHasKey(
                $alter['table'],
                $this->lifetimes,
                "`{$alter['table']}` is altered by a migration with no matching Schema::create(). If the table "
                .'belongs to the host app or another package, declare it: externalTables: [\''.$alter['table'].'\'].',
            );

            // A Schema::table() ALTER must run while its table exists: after a CREATE, before a drop.
            if (! $this->liveAt($alter['table'], $alter['at'])) {
                Assert::fail($this->createdBefore($alter['table'], $alter['at'])
                    ? "`{$alter['table']}` is altered after it is dropped."
                    : "`{$alter['table']}` is altered before it is created.");
            }
        }

        if ($expectedForeignKeys !== null) {
            Assert::assertCount(
                $expectedForeignKeys,
                $this->edges,
                "Expected {$expectedForeignKeys} foreign keys but parsed ".count($this->edges).'.',
            );
        }

        foreach ($this->edges as $edge) {
            if ($this->isExternal($edge->parent)) {
                continue;
            }

            Assert::assertArrayHasKey(
                $edge->parent,
                $this->lifetimes,
                "`{$edge->child}` references `{$edge->parent}`, which no migration creates. If the table belongs "
                .'to the host app or another package, declare it: externalTables: [\''.$edge->parent.'\'].',
            );

            if ($edge->isSelfReferencing()) {
                // A self-referencing key is satisfied by the table's own CREATE block or a
                // later ALTER — never by a table created afterwards.
                Assert::assertTrue(
                    $this->liveAt($edge->parent, $edge->at, inclusive: true),
                    "`{$edge->child}` self-references a table created after its own migration.",
                );

                continue;
            }

            if (! $this->liveAt($edge->parent, $edge->at)) {
                Assert::fail($this->createdBefore($edge->parent, $edge->at)
                    ? "`{$edge->child}` references `{$edge->parent}`, which is dropped before it."
                    : "`{$edge->child}` references `{$edge->parent}`, which must be created first.");
            }
        }

        $this->assertExternalTablesAreUsed();
    }

    private function isExternal(string $table): bool
    {
        return in_array($table, $this->externalTables, true) && ! array_key_exists($table, $this->lifetimes);
    }

    /**
     * Whether $table exists at ordinal $at: some lifetime began before it (at it, when
     * $inclusive — a table's own CREATE block satisfies its self-reference) and had not ended.
     */
    private function liveAt(string $table, int $at, bool $inclusive = false): bool
    {
        foreach ($this->lifetimes[$table] ?? [] as $life) {
            $begun = $inclusive ? $life['from'] <= $at : $life['from'] < $at;

            if ($begun && ($life['to'] === null || $life['to'] >= $at)) {
                return true;
            }
        }

        return false;
    }

    private function createdBefore(string $table, int $at): bool
    {
        foreach ($this->lifetimes[$table] ?? [] as $life) {
            if ($life['from'] < $at) {
                return true;
            }
        }

        return false;
    }

    /**
     * Begin a lifetime of $table at $at. A CREATE while the table is still live is refused by
     * every engine, so the set is not runnable.
     */
    private function begin(string $table, int $at): void
    {
        $lives = $this->lifetimes[$table] ?? [];
        $last = $lives === [] ? null : $lives[array_key_last($lives)];

        Assert::assertFalse(
            $last !== null && $last['to'] === null,
            "`{$table}` is created twice with no drop in between — the second Schema::create() fails on every engine.",
        );

        $this->lifetimes[$table][] = ['from' => $at, 'to' => null];
    }

    /**
     * End the live lifetime of $table at $at. Dropping a table the set never created (a
     * `dropIfExists()` of a host table, or of nothing) ends nothing.
     */
    private function end(string $table, int $at): void
    {
        $last = array_key_last($this->lifetimes[$table] ?? []);

        if ($last !== null && $this->lifetimes[$table][$last]['to'] === null) {
            $this->lifetimes[$table][$last]['to'] = $at;
        }
    }

    /**
     * Rot check: an external table nothing references is an exemption that exempts nothing.
     */
    private function assertExternalTablesAreUsed(): void
    {
        $referenced = [
            ...array_map(static fn (ForeignKeyEdge $edge): string => $edge->parent, $this->edges),
            ...array_column($this->alters, 'table'),
        ];

        $unused = array_values(array_diff($this->externalTables, $referenced));

        Assert::assertSame(
            [],
            $unused,
            'These externalTables entries are referenced by no foreign key and no ALTER in the set: '
            .implode(', ', $unused).'. An entry that silences nothing is a typo or has outlived its migration. Remove it.',
        );
    }

    private function parse(string $directory): void
    {
        // The same file list the real-engine runner applies, so the two cannot disagree about
        // what "the set" is.
        $files = MigrationFiles::sorted($directory);

        // Guard the guard: zero files is an empty parse, and an empty parse satisfies every
        // check below — `foreignKeys: 0` included. A publish-only stub directory reads the
        // same way, because `*.php.stub` is not a migration until it is published.
        Assert::assertNotSame(
            [],
            $files,
            "No migration files (*.php) in {$directory}, so there is no order to pin. A publish-only "
            .'*.php.stub set is not read: point the assertion at the directory that holds the migrations.',
        );

        $ordinal = 0;
        $parsed = [];

        $ordered = 0;

        // Pass one numbers every block in run order and records every table's lifetimes across
        // the whole directory, so ALTER and key validation can see a CREATE that (wrongly) runs
        // *after*. Only up() is read: down() never runs on the way to a migrated schema.
        foreach ($files as $file) {
            $source = MigrationSource::withoutMethod(
                MigrationSource::withoutComments((string) file_get_contents($file)),
                'down',
            );
            $blocks = $this->blocks($source, $file, $ordinal);

            foreach ($blocks as $block) {
                match ($block['kind']) {
                    'create' => $this->begin($block['table'], $block['at']),
                    'drop' => $this->end($block['table'], $block['at']),
                    default => null,
                };

                $ordered += in_array($block['kind'], ['create', 'table'], true) ? 1 : 0;
            }

            $parsed[] = [$file, $source, $blocks];
        }

        Assert::assertGreaterThan(
            0,
            $ordered,
            "The migrations in {$directory} hold no Schema::create() or Schema::table() block, so there "
            .'is no order to pin — a pass would be a verdict over an empty parse.',
        );

        foreach ($parsed as [$file, $source, $blocks]) {
            $imports = MigrationSource::imports($source);
            $constrained = 0;
            $references = 0;

            foreach ($blocks as $block) {
                if ($block['kind'] === 'table') {
                    $this->alters[] = ['table' => $block['table'], 'at' => $block['at']];
                }

                // A drop declares no column. A key after it in the same span has no block to
                // belong to, so it stays unparsed and the guard below names it.
                if ($block['kind'] === 'drop') {
                    continue;
                }

                [$constrainedHere, $referencesHere] = $this->parseEdges($block, $imports, basename($file));

                $constrained += $constrainedHere;
                $references += $referencesHere;
            }

            // Guard the guard: nothing may go unparsed. Counted on the comment-free source, so
            // a commented-out key is neither parsed nor demanded.
            Assert::assertSame(
                preg_match_all('/->\s*constrained\s*\(/', $source),
                $constrained,
                basename($file).': a `->constrained()` foreign key could not be parsed — the order pin would miss it.',
            );
            Assert::assertSame(
                preg_match_all('/->\s*references\s*\(/', $source),
                $references,
                basename($file).': a `->references()->on()` foreign key could not be parsed.',
            );
        }
    }

    /**
     * Split a migration file into its Schema::create()/table()/drop()/dropIfExists() blocks,
     * each carrying the table it operates on, its ordinal in run order and the source span up
     * to the next block. Both drops normalise to the kind `drop`.
     *
     * @return list<array{kind: string, table: string, body: string, at: int}>
     */
    private function blocks(string $body, string $file, int &$ordinal): array
    {
        // A table argument holds no top-level comma; one level of nested parens is
        // allowed so `Schema::create(SomeClass::table(), ...)` still matches.
        $argument = '((?:[^(),]|\([^()]*\))*?)';

        preg_match_all(
            '/Schema::(create|table|dropIfExists|drop)\(\s*'.$argument.'\s*[,)]/',
            $body,
            $matches,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
        );

        $blocks = [];
        $total = count($matches);

        foreach ($matches as $position => $match) {
            $start = (int) $match[0][1];
            $end = $position + 1 < $total ? (int) $matches[$position + 1][0][1] : strlen($body);

            $blocks[] = [
                'kind' => str_starts_with((string) $match[1][0], 'drop') ? 'drop' : (string) $match[1][0],
                'table' => $this->resolver->resolveSchemaTable((string) $match[2][0], basename($file)),
                'body' => substr($body, $start, $end - $start),
                'at' => $ordinal++,
            ];
        }

        return $blocks;
    }

    /**
     * Parse the foreign-key edges out of one Schema block, attributing each to the block's
     * table at the block's ordinal. Statements are split on real `;` tokens, which keeps a
     * bare `->constrained()` next to the `foreignId()` / `foreignIdFor()` column it derives
     * its parent from.
     *
     * @param  array{kind: string, table: string, body: string, at: int}  $block
     * @param  array<string, string>  $imports
     * @return array{0: int, 1: int} [constrained count, references count]
     */
    private function parseEdges(array $block, array $imports, string $context): array
    {
        $constrained = 0;
        $references = 0;

        foreach (MigrationSource::statements($block['body']) as $tokens) {
            $column = '';
            $boundTable = null;
            $onCalls = [];
            $referenceCount = 0;
            $constrainedCalls = [];

            foreach (array_keys($tokens) as $i) {
                [$id, $text] = $tokens[$i];

                if ($id === T_STRING && in_array(strtolower($text), ['foreignid', 'foreignuuid', 'foreignulid'], true)
                    && ($open = MigrationSource::methodCallAt($tokens, $i, $text)) !== null) {
                    $first = MigrationSource::arguments($tokens, $open)[0] ?? null;

                    // Only a literal names a column; anything else leaves a bare constrained()
                    // underivable, which fails by name rather than inventing a table.
                    $column = $first !== null && preg_match('/^([\'"])([a-z0-9_]+)\1$/i', $first->text, $literal) === 1
                        ? $literal[2]
                        : '';

                    continue;
                }

                if (($open = MigrationSource::methodCallAt($tokens, $i, 'foreignIdFor')) !== null) {
                    $boundTable = $this->resolver->resolveModelTable(MigrationSource::arguments($tokens, $open), $imports, $context);

                    continue;
                }

                if (($open = MigrationSource::methodCallAt($tokens, $i, 'constrained')) !== null) {
                    $constrainedCalls[] = MigrationSource::arguments($tokens, $open);

                    continue;
                }

                if (MigrationSource::methodCallAt($tokens, $i, 'references') !== null) {
                    $referenceCount++;

                    continue;
                }

                if (($open = MigrationSource::methodCallAt($tokens, $i, 'on')) !== null) {
                    $onCalls[] = MigrationSource::arguments($tokens, $open);
                }
            }

            foreach ($constrainedCalls as $arguments) {
                $this->edges[] = new ForeignKeyEdge(
                    $block['table'],
                    $this->resolver->resolveConstrained($arguments, $column, $boundTable, $context),
                    $block['at'],
                );
                $constrained++;
            }

            for ($offset = 0; $offset < $referenceCount; $offset++) {
                $this->edges[] = new ForeignKeyEdge(
                    $block['table'],
                    $this->resolver->resolveOn($onCalls[$offset] ?? [], $context),
                    $block['at'],
                );
                $references++;
            }
        }

        return [$constrained, $references];
    }
}
