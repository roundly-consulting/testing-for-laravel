<?php

declare(strict_types=1);

final class Registry
{
    /**
     * The driver name is a runtime value, so every read below is interpolated. Each one
     * still names a literal *leaf* — `url`, `token` — under the driver hole.
     */
    public function url(string $key): ?string
    {
        $url = config("shop.providers.{$key}.url");

        return is_string($url) ? $url : null;
    }

    public function token(string $key): ?string
    {
        $token = config("shop.providers.{$key}.token");

        return is_string($token) ? $token : null;
    }
}
