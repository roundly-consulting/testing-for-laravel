<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

/**
 * The result of scraping one source file for config reads: the literal dotted keys
 * it reads, and any interpolated/concatenated key expressions under the prefix that
 * the scraper refuses to guess at.
 */
final readonly class ScrapedFile
{
    /**
     * @param  list<string>  $reads  literal dotted config keys the file reads
     * @param  list<string>  $interpolations  human-readable snippets of dynamic key reads under the prefix
     */
    public function __construct(
        public array $reads,
        public array $interpolations,
    ) {}
}
