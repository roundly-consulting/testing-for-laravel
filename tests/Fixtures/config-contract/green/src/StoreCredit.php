<?php

declare(strict_types=1);

return function (): void {
    config('shop.payments.gateway');
    config('shop.payments.currency');
    config('shop.allow_store_credit');
};
