<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

/**
 * Whether a docblock carries the `@internal` **tag** — at the start of a docblock line, or
 * inline as `{@internal …}`. Prose that merely mentions the word (`not marked @internal`)
 * does not count: reading it as the tag would silently drop a host-facing action (or a
 * public method) out of every facade check.
 */
final class InternalTag
{
    public static function in(string $docComment): bool
    {
        return preg_match('~(?:^|\R)[ \t]*(?:/\*\*|\*)?[ \t]*@internal\b|\{@internal\b~', $docComment) === 1;
    }
}
