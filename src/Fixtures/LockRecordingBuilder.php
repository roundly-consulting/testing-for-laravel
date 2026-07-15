<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Testing\Fixtures\Concerns\RecordsLocks;

/**
 * Variant A of the lock recorder: an Eloquent builder that records every
 * `lock()` / `lockForUpdate()` / `sharedLock()` call — and the transaction depth it
 * happened at — into {@see LockRecorder}, then forwards the call to the underlying
 * query so the statement runs exactly as it would have.
 *
 * A model opts in with the {@see RecordsLocks}
 * trait, which returns this builder from `newEloquentBuilder()`. Use this variant when
 * the model under test is subclassable in the suite (coupons / credits / two-factor
 * shape).
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends Builder<TModel>
 */
class LockRecordingBuilder extends Builder
{
    /**
     * @param  bool|string  $value
     * @return $this
     */
    public function lock($value = true)
    {
        $this->recordLock(match (true) {
            $value === true => 'lock-for-update',
            $value === false => 'lock-shared',
            default => 'lock-custom',
        });

        // Forward to the underlying query builder (Eloquent Builder itself forwards
        // these via __call, so there is no parent::lock() to defer to).
        $this->getQuery()->lock($value);

        return $this;
    }

    /**
     * @return $this
     */
    public function lockForUpdate()
    {
        return $this->lock(true);
    }

    /**
     * @return $this
     */
    public function sharedLock()
    {
        return $this->lock(false);
    }

    private function recordLock(string $marker): void
    {
        LockRecorder::record($marker, $this->getModel()->getConnection()->transactionLevel());
    }
}
