<?php

declare(strict_types=1);

// Every leaf is read ONLY through a package-toolkit-for-laravel reader — none by `config()`.
return [
    'mode' => 'fast',
    'host' => 'shop.test',
    'retries' => 3,
    'strict' => true,
    'order' => 'latest',
    'key_type' => 'bigint',
    'model' => 'App\\Models\\Shop',
    'tax' => ['resolver' => null],
    'routes' => ['enabled' => true],
    'facade_alias' => true,
    'rate_limits' => [
        'places' => ['per' => 'minute', 'limit' => 60],
        'routes' => ['per' => 'minute', 'limit' => 60],
    ],
];
