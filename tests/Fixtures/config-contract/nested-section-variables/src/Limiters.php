<?php

declare(strict_types=1);

return function (): void {
    $rl = config('shop.rate_limiters');

    $publicOn = $rl['public']['enabled'];
    $publicRate = $rl['public']['per_minute'];
    $apiOn = $rl['api']['enabled'];
    $apiRate = $rl['api']['per_minute'];

    unset($publicOn, $publicRate, $apiOn, $apiRate);
};
