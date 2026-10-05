<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RoundlyConsulting\Testing\Fixtures\Concerns\RecordsLocks;

/**
 * Variant A of the lock recorder: an Eloquent builder that records every pessimistic lock
 * (`lock()` / `lockForUpdate()` / `sharedLock()`) its query **executes** — and the transaction
 * depth it executed at — into {@see LockRecorder}, while the statement runs exactly as it would
 * have.
 *
 * The record is taken when the query runs (a `beforeQuery()` callback), not when the builder
 * method is called: a builder made outside a transaction and run inside one took its lock at
 * that inner depth, and a locking builder that never runs took no lock at all. A query compiled
 * with `toSql()` counts as run. A builder executed a second time records again only once a lock
 * method has been called on it again.
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
    /** The recorder registered on this builder's query, so a second lock call arms it once. */
    private ?Closure $recorder = null;

    /**
     * @param  bool|string  $value
     * @return $this
     */
    public function lock($value = true)
    {
        // Forward to the underlying query builder (Eloquent Builder itself forwards
        // these via __call, so there is no parent::lock() to defer to).
        $query = $this->getQuery();
        $query->lock($value);

        $this->recorder ??= static function (QueryBuilder $query): void {
            // Read the lock as it stands when the statement runs, not as it was requested.
            if ($query->lock === null) {
                return;
            }

            LockRecorder::record(match (true) {
                $query->lock === true => 'lock-for-update',
                $query->lock === false => 'lock-shared',
                default => 'lock-custom',
            }, $query->getConnection()->transactionLevel());
        };

        if (! in_array($this->recorder, $query->beforeQueryCallbacks, true)) {
            $query->beforeQuery($this->recorder);
        }

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
}
