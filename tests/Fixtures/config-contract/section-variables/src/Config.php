<?php

declare(strict_types=1);

return function (): void {
    // Reads the subtree wholesale, then its leaves by offset — never through config().
    $rp = config('shop.rp');

    $id = $rp['id'];
    $name = $rp['name'];

    unset($id, $name);
};
