<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions\PublishWidget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\DataTransferObjects\WidgetSummary;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Enums\WidgetState;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;

/**
 * Returns a model, a DTO and an enum — data, not surface. ArchiveWidget is referenced only
 * by the model and the DTO, so it is unreachable from the facade.
 */
final class WidgetsManager
{
    public function publish(Widget $widget): void
    {
        app(PublishWidget::class)->execute($widget);
    }

    public function find(int $id): Widget|WidgetSummary|null
    {
        return null;
    }

    public function summary(): WidgetSummary
    {
        return new WidgetSummary(0);
    }

    public function state(): WidgetState
    {
        return WidgetState::Draft;
    }
}
