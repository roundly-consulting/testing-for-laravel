<?php

declare(strict_types=1);

/*
 * A driver-keyed section: the Laravel-canonical `database.connections.<name>` shape.
 * The driver segment is chosen at runtime, so no literal read can ever name it.
 */
return [
    'providers' => [
        'github' => [
            'url' => 'https://api.github.com',
            'token' => null,
            'rate_limits' => [
                'enabled' => true,
                'jitter' => null,
            ],
        ],
        'gitlab' => [
            'url' => 'https://gitlab.com/api/v4',
            'token' => null,
            'rate_limits' => [
                'enabled' => true,
                'jitter' => null,
            ],
        ],
    ],
];
