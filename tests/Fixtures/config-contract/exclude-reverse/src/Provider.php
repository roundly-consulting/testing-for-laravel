<?php

declare(strict_types=1);

return function (): array {
    // Reads the key purely to display it in `artisan about` — a render, not a use.
    return ['Rendered' => config('shop.rendered')];
};
