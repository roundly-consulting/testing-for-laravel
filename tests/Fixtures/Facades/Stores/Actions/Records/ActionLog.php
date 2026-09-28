<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\Records;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Stores\Actions\AddItem;

/**
 * A model under Actions\ — actions compose actions, so it is never a subject.
 */
class ActionLog extends Model
{
    public function replay(): void
    {
        app(AddItem::class)->execute('replay');
    }
}
