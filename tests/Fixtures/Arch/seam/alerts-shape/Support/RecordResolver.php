<?php

declare(strict_types=1);

namespace Fixture\AlertsShape\Support;

/** The legitimate seam: the only place allowed to read the swap keys. */
final class RecordResolver
{
    public static function alert(): string
    {
        return (string) config('alerts.alert');
    }

    public static function healthCheck(): string
    {
        return (string) config('alerts.health-check');
    }

    public static function silence(): string
    {
        return (string) config('alerts.silence-model');
    }

    public static function historyRun(): string
    {
        return (string) config('alerts.history.model');
    }
}
