<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;

final readonly class PublishWidget
{
    public function __construct(private NotifySubscribers $notify) {}

    public function execute(Widget $widget): void
    {
        $this->notify->execute($widget);
    }
}
