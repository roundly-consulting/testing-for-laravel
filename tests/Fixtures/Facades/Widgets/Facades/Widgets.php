<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Facades;

use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\DataTransferObjects\WidgetSummary;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Enums\WidgetState;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\WidgetsManager;

/**
 * @method static void publish(Widget $widget)
 * @method static Widget|WidgetSummary|null find(int $id)
 * @method static WidgetSummary summary()
 * @method static WidgetState state()
 *
 * @see WidgetsManager
 */
final class Widgets extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WidgetsManager::class;
    }
}
