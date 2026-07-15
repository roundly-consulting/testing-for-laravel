<?php

declare(strict_types=1);

return function (): void {
    config('shop.used');

    // Not a config() call — only extraReadPrefixes makes this literal count as a read.
    ModelResolver::for('shop.model');
};
