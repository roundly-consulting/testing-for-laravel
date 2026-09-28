<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Traits;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions as WidgetActions;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;

/**
 * Reaches an action through an aliased namespace import — still a direct action call.
 */
trait HasWidgets
{
    public function publishWidget(Widget $widget): void
    {
        app(WidgetActions\PublishWidget::class)->execute($widget);
    }
}
