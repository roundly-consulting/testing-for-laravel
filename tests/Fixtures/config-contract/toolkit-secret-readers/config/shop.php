<?php

declare(strict_types=1);

// Every leaf is read ONLY through the toolkit's secret(), requireSecret() or secretList() reader.
return [
    'api_key' => null,
    'signing' => ['secret' => null],
    'keys' => [],
    'webhook_secret' => null,
    'token' => null,
    'ring' => [],
    'password' => null,
    'previous_keys' => [],
    'docs_gate' => ['secret' => ''],
    'client_secret' => null,
    'peers' => [],
];
