<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Locks;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Fixtures\Concerns\RecordsLocks;

/**
 * Variant A fixture: a subclassable model that records every lock it takes through the
 * {@see RecordsLocks} trait.
 */
class RecordingWidget extends Model
{
    use RecordsLocks;

    protected $table = 'lock_widgets';

    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}
