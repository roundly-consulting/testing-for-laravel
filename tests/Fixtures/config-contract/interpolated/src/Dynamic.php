<?php

declare(strict_types=1);

return function (string $name): void {
    // A dynamic key directly under the prefix root: it names no literal leaf, and strips to
    // a bare `shop` that no forward check could test. The scraper cannot check it and must
    // flag it — there is no allow-list escape, and none is advertised.
    config("shop.{$name}");
};
