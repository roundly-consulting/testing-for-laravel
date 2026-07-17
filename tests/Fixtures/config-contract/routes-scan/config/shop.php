<?php

declare(strict_types=1);

return [
    'used' => true,
    // Read ONLY from routes/shop.php. A real read — deleting this key would
    // unregister the webhook route's middleware.
    'webhook_middleware' => ['api'],
    // Read nowhere at all, routes/ included. A genuine dead key: must stay RED,
    // or widening the scope to routes/ would just be an amnesty.
    'escalation_after' => 30,
];
