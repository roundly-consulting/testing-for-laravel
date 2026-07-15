<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Locks;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * Variant B fixture: a model with no lock-recording trait — it stands in for a model you
 * cannot subclass, whose locks are observed only through {@see LockRecordingGrammar}.
 */
class PlainWidget extends Model
{
    protected $table = 'lock_widgets';

    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}
