<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

/**
 * The result of scraping one source file for config reads: the dotted keys it reads, and
 * any key expressions under the prefix that the scraper refuses to guess at.
 *
 * A read may carry `*` holes where a driver name is interpolated — see {@see KeyPattern}.
 */
final readonly class ScrapedFile
{
    /**
     * @param  list<string>  $reads  dotted config keys the file reads, `*` for a driver hole
     * @param  list<string>  $interpolations  human-readable snippets of dynamic keys that resolve to no checkable pattern
     * @param  list<string>  $dynamicSections  paths read wholesale through a trailing hole, kept only to explain a reverse failure
     * @param  list<string>  $prefixedReads  reads counted only because they matched an `extraReadPrefixes` entry
     */
    public function __construct(
        public array $reads,
        public array $interpolations,
        public array $dynamicSections = [],
        public array $prefixedReads = [],
    ) {}
}
