<?php

declare(strict_types=1);

return [
    'guards' => [],                 // an empty map the host fills
    'drivers' => ['file', 'redis'], // a list
    'connection' => null,           // unset until the host configures it
];
