<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Shadow;

/**
 * Abstract, and prefix-shadowed by `Provider`. It must never be reported: `abstract final`
 * is a PHP fatal, so it can never satisfy the ban, and `finalByDefault` excludes abstracts
 * by construction. The recovery has to share that population or it invents a violation the
 * preset itself would not raise — and one nobody could fix.
 */
abstract class ProviderBase
{
    abstract public function handle(): string;
}
