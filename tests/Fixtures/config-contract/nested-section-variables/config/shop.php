<?php

declare(strict_types=1);

// The cosmos-foundation shape: a section of named profiles, each with its own leaves. The
// code takes the section wholesale and indexes TWO levels in.
return [
    'rate_limiters' => [
        'public' => [
            'enabled' => true,
            'per_minute' => 60,
        ],
        'api' => [
            'enabled' => true,
            'per_minute' => 120,
        ],
    ],
];
