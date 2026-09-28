<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Actions\ArchiveWidget;

/**
 * Calls an action straight from a model method — the bypass a fake never sees.
 */
class Widget extends Model
{
    public function archive(): void
    {
        app(ArchiveWidget::class)->execute($this);
    }
}
