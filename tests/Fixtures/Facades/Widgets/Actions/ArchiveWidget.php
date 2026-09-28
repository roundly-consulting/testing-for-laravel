<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions;

use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models\Widget;

final class ArchiveWidget
{
    public function execute(Widget $widget): void {}
}
