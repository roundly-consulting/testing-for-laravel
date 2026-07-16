<?php

declare(strict_types=1);

// Not a Migration at all. Must fail by name rather than be quietly skipped — a silently
// skipped migration file is the same bug class as a silently skipped down().
return ['not' => 'a migration'];
