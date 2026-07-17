<?php

declare(strict_types=1);

final class RateLimits
{
    /**
     * The section-into-a-local shape: the base path itself carries the driver hole, and
     * the leaves are then proven by literal offsets on the local.
     */
    public function enabled(string $key): bool
    {
        /** @var array<string, mixed> $limits */
        $limits = config("shop.providers.{$key}.rate_limits", []);

        if (($limits['enabled'] ?? true) === false) {
            return false;
        }

        return $limits['jitter'] === null;
    }
}
