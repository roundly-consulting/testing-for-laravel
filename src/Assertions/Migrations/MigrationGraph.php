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
 * Guard-the-guard: the number of foreign keys parsed must equal the raw
 * `->constrained(` / `->references(` occurrence count in each file. An unparseable
 * declaration fails the assertion — it is never silently dropped, because a pin that
 * quietly parses nothing passes vacuously.
 */
final class MigrationGraph
{
    /** @var array<string, int> table name => position in directory order */
    private array $createdAt = [];

    /** @var list<ForeignKeyEdge> */
    private array $edges = [];

    /** @var list<array{table: string, at: int}> */
    private array $alters = [];

    public function __construct(private readonly TableResolver $resolver) {}

    /** @param array<string, string> $tableResolvers */
    public static function forDirectory(string $directory, array $tableResolvers = []): self
    {
        $graph = new self(new TableResolver($tableResolvers));
        $graph->parse($directory);

        return $graph;
    }

    public function assertRunnable(?int $expectedForeignKeys): void
    {
        foreach ($this->alters as $alter) {
            Assert::assertArrayHasKey(
                $alter['table'],
                $this->createdAt,
                "`{$alter['table']}` is altered by a migration with no matching Schema::create().",
            );

            // A Schema::table() ALTER must sort at or after the CREATE of its table.
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
            Assert::assertArrayHasKey(
                $edge->parent,
                $this->createdAt,
                "`{$edge->child}` references `{$edge->parent}`, which no migration creates.",
            );

            if ($edge->isSelfReferencing()) {
                // A self-referencing key is satisfied by the table's own CREATE (same
                // file) or a later ALTER — never by a table created afterwards.
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
    }

    private function parse(string $directory): void
    {
        Assert::assertDirectoryExists($directory, "Migrations directory does not exist: {$directory}");

        $files = glob(rtrim($directory, '/').'/*.php') ?: [];
        sort($files);

        // Pass one records every CREATE across the whole directory first, so ALTER
        // validation can see a CREATE that (wrongly) sorts *after* it.
        foreach ($files as $index => $file) {
            foreach ($this->blocks((string) file_get_contents($file), $file) as $block) {
                if ($block['kind'] === 'create') {
                    $this->createdAt[$block['table']] = $index;
                }
            }
        }

        foreach ($files as $index => $file) {
            $body = (string) file_get_contents($file);
            $constrained = 0;
            $references = 0;

            foreach ($this->blocks($body, $file) as $block) {
                if ($block['kind'] === 'table') {
                    $this->alters[] = ['table' => $block['table'], 'at' => $index];
                }

                [$constrainedHere, $referencesHere] = $this->parseEdges(
                    $block['body'],
                    $block['table'],
                    $index,
                    $file,
                );

                $constrained += $constrainedHere;
                $references += $referencesHere;
            }

            // Guard the guard: nothing may go unparsed.
            Assert::assertSame(
                substr_count($body, '->constrained('),
                $constrained,
                basename($file).': a `->constrained()` foreign key could not be parsed — the order pin would miss it.',
            );
            Assert::assertSame(
                substr_count($body, '->references('),
                $references,
                basename($file).': a `->references()->on()` foreign key could not be parsed.',
            );
        }
    }

    /**
     * Split a migration file into its Schema::create()/table() blocks, each carrying
     * the table it operates on and the source span up to the next block.
     *
     * @return list<array{kind: string, table: string, body: string}>
     */
    private function blocks(string $body, string $file): array
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
            ];
        }

        return $blocks;
    }

    /**
     * Parse foreign-key edges out of one Schema block, attributing each to $child.
     * Splitting on `;` keeps a bare `->constrained()` next to the `foreignId()`
     * column it derives its parent from.
     *
     * @return array{0: int, 1: int} [constrained count, references count]
     */
    private function parseEdges(string $blockBody, string $child, int $index, string $file): array
    {
        $argument = '((?:[^()]|\([^()]*\))*)';
        $constrained = 0;
        $references = 0;

        foreach (explode(';', $blockBody) as $statement) {
            if (! str_contains($statement, '->constrained(') && ! str_contains($statement, '->references(')) {
                continue;
            }

            preg_match('/foreign(?:Id|Uuid|Ulid)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $statement, $columnMatch);
            $column = (string) ($columnMatch[1] ?? '');

            if (preg_match_all('/->constrained\(\s*'.$argument.'\s*\)/', $statement, $constrainedMatches) >= 1) {
                foreach ($constrainedMatches[1] as $rawArgument) {
                    $this->edges[] = new ForeignKeyEdge(
                        $child,
                        $this->resolver->resolveConstrained((string) $rawArgument, $column, basename($file)),
                        $index,
                    );
                    $constrained++;
                }
            }

            if (preg_match_all('/->references\(\s*'.$argument.'\s*\)/', $statement, $referencesMatches) >= 1) {
                preg_match_all('/->on\(\s*'.$argument.'\s*\)/', $statement, $onMatches);

                foreach (array_keys($referencesMatches[1]) as $offset) {
                    $this->edges[] = new ForeignKeyEdge(
                        $child,
                        $this->resolver->resolveOn((string) ($onMatches[1][$offset] ?? ''), basename($file)),
                        $index,
                    );
                    $references++;
                }
            }
        }

        return [$constrained, $references];
    }
}
