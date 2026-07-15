<?php

declare(strict_types=1);

return function (string $name): void {
    // A dynamic key under the prefix — the scraper cannot check it and must flag it.
    config("shop.drivers.{$name}");
};
