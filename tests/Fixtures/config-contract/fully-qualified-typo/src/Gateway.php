<?php

declare(strict_types=1);

namespace Shop;

final class Gateway
{
    public function key(): mixed
    {
        return \config('shop.payments.key'); // typo: the file ships `payment.key`
    }

    public function rest(): void
    {
        config('shop.payment.key');
        config('shop.mode');
        config('shop.region');
        config('shop.currency');
    }
}
