<?php

declare(strict_types=1);

/**
 * git's real BaseProvider shape: the whole driver section is pulled into a local through a
 * dynamic key, then indexed. Without a sectionVariables mapping, the offsets prove nothing
 * and every leaf reads as dead — so the reverse failure has to explain itself.
 */
final class BaseProvider
{
    public function client(): array
    {
        /** @var array<string, mixed> $http */
        $http = config("shop.providers.{$this->key()}", []);

        return [
            'timeout' => $http['timeout'] ?? 10,
            'url' => $http['url'] ?? null,
        ];
    }

    private function key(): string
    {
        return 'github';
    }
}
