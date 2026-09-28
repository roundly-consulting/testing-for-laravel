<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\DataTransferObjects;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions\ArchiveWidget;

/**
 * Data, not API: referencing an action here must not make it reachable.
 */
final readonly class WidgetSummary
{
    public function __construct(public int $count) {}

    public function archiver(): string
    {
        return ArchiveWidget::class;
    }
}
