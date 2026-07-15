<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\Green\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\Green\Support\RecordResolver;

/**
 * A model that reaches its (swappable) record class only through the seam — no late
 * static binding, no config literal of its own.
 */
class Widget extends Model
{
    protected $guarded = [];

    public function records(): mixed
    {
        return RecordResolver::query()->get();
    }
}
