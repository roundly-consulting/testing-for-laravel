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
    /** @var array<string, int> table name => ordinal of the Schema block that creates it */
    private array $createdAt = [];

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
                $this->createdAt,
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
                $this->createdAt,
                "`{$alter['table']}` is altered by a migration with no matching Schema::create(). If the table "
                .'belongs to the host app or another package, declare it: externalTables: [\''.$alter['table'].'\'].',
            );

            // A Schema::table() ALTER must run after the CREATE of its table.
            Assert::assertLessThanOrEqual(
                $alter['at'],
                $this->createdAt[$alter['table']],
                "`{$alter['table']}` is altered before it is created.",
            );
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
                $this->createdAt,
                "`{$edge->child}` references `{$edge->parent}`, which no migration creates. If the table belongs "
                .'to the host app or another package, declare it: externalTables: [\''.$edge->parent.'\'].',
            );

            if ($edge->isSelfReferencing()) {
                // A self-referencing key is satisfied by the table's own CREATE block or a
                // later ALTER — never by a table created afterwards.
                Assert::assertLessThanOrEqual(
                    $edge->at,
                    $this->createdAt[$edge->parent],
                    "`{$edge->child}` self-references a table created after its own migration.",
                );

                continue;
            }

            Assert::assertLessThan(
                $edge->at,
                $this->createdAt[$edge->parent],
                "`{$edge->child}` references `{$edge->parent}`, which must be created first.",
            );
        }

        $this->assertExternalTablesAreUsed();
    }

    private function isExternal(string $table): bool
    {
        return in_array($table, $this->externalTables, true) && ! array_key_exists($table, $this->createdAt);
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
        Assert::assertDirectoryExists($directory, "Migrations directory does not exist: {$directory}");

        $files = glob(rtrim($directory, '/').'/*.php') ?: [];
        sort($files);

        $ordinal = 0;
        $parsed = [];

        // Pass one numbers every block in run order and records every CREATE across the whole
        // directory, so ALTER and key validation can see a CREATE that (wrongly) runs *after*.
        foreach ($files as $file) {
            $source = MigrationSource::withoutComments((string) file_get_contents($file));
            $blocks = $this->blocks($source, $file, $ordinal);

            foreach ($blocks as $block) {
                if ($block['kind'] === 'create') {
                    $this->createdAt[$block['table']] = $block['at'];
                }
            }

            $parsed[] = [$file, $source, $blocks];
        }

        foreach ($parsed as [$file, $source, $blocks]) {
            $imports = MigrationSource::imports($source);
            $constrained = 0;
            $references = 0;

            foreach ($blocks as $block) {
                if ($block['kind'] === 'table') {
                    $this->alters[] = ['table' => $block['table'], 'at' => $block['at']];
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
     * Split a migration file into its Schema::create()/table() blocks, each carrying
     * the table it operates on, its ordinal in run order and the source span up to the
     * next block.
     *
     * @return list<array{kind: string, table: string, body: string, at: int}>
     */
    private function blocks(string $body, string $file, int &$ordinal): array
    {
        // A table argument holds no top-level comma; one level of nested parens is
        // allowed so `Schema::create(SomeClass::table(), ...)` still matches.
        $argument = '((?:[^(),]|\([^()]*\))*?)';

        preg_match_all(
            '/Schema::(create|table)\(\s*'.$argument.'\s*,/',
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
                'kind' => (string) $match[1][0],
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
