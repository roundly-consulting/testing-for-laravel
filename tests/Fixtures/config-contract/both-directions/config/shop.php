<?php

declare(strict_types=1);

return [
    // Read by the code — the contract's forward direction is satisfied for this one.
    'currency' => 'EUR',

    // Shipped but read by nothing: the reverse direction's dead-key class (media #27).
    'max_file_size' => 2048,
    'escalation_after' => 30,
];
