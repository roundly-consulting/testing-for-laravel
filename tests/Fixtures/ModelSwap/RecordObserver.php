<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

/**
 * Hung on the *configured* model class by {@see ModelSwapServiceProvider} at boot — the
 * provider-side seam. When the swap is applied before boot the observer lands on the
 * host subclass; a lock done after boot would leave it on the packaged class instead.
 */
final class RecordObserver
{
    public static bool $created = false;

    public static function reset(): void
    {
        self::$created = false;
    }

    public function created(Record $record): void
    {
        self::$created = true;
    }
}
