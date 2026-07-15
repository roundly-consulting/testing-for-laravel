<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Fixtures\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingBuilder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/**
 * Variant A opt-in: a model that uses this trait records every pessimistic lock it
 * takes — and the transaction depth it took it at — into {@see LockRecorder},
 * because it returns a {@see LockRecordingBuilder} from `newEloquentBuilder()`.
 *
 * ```php
 * final class RecordingCoupon extends Coupon { use RecordsLocks; }
 *
 * LockRecorder::flush();
 * // … drive the race, which calls Coupon::query()->lockForUpdate() …
 * expect(LockRecorder::recorded())->toHaveCount(1);
 * ```
 *
 * Use this when the model is subclassable in the suite. When it is not (the lock is
 * buried in a package action against a model you cannot swap), reach for variant B,
 * {@see LockRecordingGrammar} instead.
 *
 * @phpstan-require-extends Model
 */
trait RecordsLocks
{
    /**
     * @param  QueryBuilder  $query
     * @return LockRecordingBuilder<static>
     */
    public function newEloquentBuilder($query): LockRecordingBuilder
    {
        return new LockRecordingBuilder($query);
    }
}
