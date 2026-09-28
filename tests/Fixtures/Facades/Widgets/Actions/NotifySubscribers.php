<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;

/**
 * A building block PublishWidget composes, but not marked @internal — so it is host-facing,
 * and being reachable through another action does not count.
 */
final class NotifySubscribers
{
    public function execute(Widget $widget): void {}
}
