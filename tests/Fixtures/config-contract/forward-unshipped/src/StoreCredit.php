<?php

declare(strict_types=1);

return function (): void {
    // Reads `payments.gateway`; the file ships `payment.gateway` — a dead feature.
    config('shop.payments.gateway');
};
