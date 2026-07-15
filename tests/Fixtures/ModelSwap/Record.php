<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use Illuminate\Database\Eloquent\Model;

/**
 * The "packaged" model a host app may swap for its own subclass via
 * `config('model_swap.record_model')`. Deliberately NOT final so a host subclass is
 * possible (the seam that was shipped `final` seven times in the fleet).
 */
class Record extends Model
{
    protected $table = 'records';

    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}
