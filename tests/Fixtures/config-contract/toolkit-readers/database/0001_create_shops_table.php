<?php

declare(strict_types=1);

use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class
{
    public function up(): void
    {
        KeyType::fromConfig('shop.key_type');
    }
};
