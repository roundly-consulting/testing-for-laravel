<?php

declare(strict_types=1);

final class Checkout
{
    public function currency(): string
    {
        return (string) config('shop.currency');
    }

    public function gateway(): string
    {
        // Read but never shipped: the forward direction's silently-disabled-feature class.
        return (string) config('shop.payments.gateway');
    }
}
