<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Fixtures\Concerns;

use RoundlyConsulting\Testing\Assertions\ModelSwap;

/**
 * A host-subclass fixture trait that counts how many rows were *created as this exact
 * class* — the only proof that a configured model swap really took effect.
 *
 * `instanceof` is not enough (permissions #31): a package helper that calls
 * `static::query()->create(...)` from inside the packaged model resolves `static::`
 * to the packaged class, so the row is created as the *wrong* class and the host's
 * model events never fire — yet the returned object can still pass an `instanceof`
 * check against the host subclass. Counting `created` events on the host subclass is
 * the independent oracle: if the flow really created the row as the host's class, this
 * counter goes up; if it created it as the packaged class, it does not.
 *
 * A model using this trait is picked up by {@see ModelSwap}
 * automatically: it resets the counter before the exercised flow and asserts at least
 * one `created` event landed afterwards.
 */
trait CountsCreations
{
    /**
     * The number of rows created as the class using this trait since the last reset.
     */
    public static int $creationCount = 0;

    /**
     * Booted automatically by Eloquent (it calls `boot<TraitName>()` for every trait).
     * Hooks the model's own `created` event so the count only bumps for rows actually
     * created *as this class*.
     */
    public static function bootCountsCreations(): void
    {
        static::created(static function (): void {
            static::$creationCount++;
        });
    }

    /**
     * Reset the counter — call in `setUp()` (the model-swap assertion also resets it
     * before running the exercised flow, so the count reflects that flow alone).
     */
    public static function resetCreationCount(): void
    {
        static::$creationCount = 0;
    }

    /**
     * How many rows have been created as this class since the last reset.
     */
    public static function creationCount(): int
    {
        return static::$creationCount;
    }
}
