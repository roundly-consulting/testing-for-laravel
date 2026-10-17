<?php

declare(strict_types=1);

namespace Fixture\AlertsShape\Support;

use Fixture\AlertsShape\Models\Alert;
use Fixture\AlertsShape\Models\AlertSilence;
use Fixture\AlertsShape\Models\HealthCheck;
use Fixture\AlertsShape\Models\HealthCheckRun;

/** The legitimate seam: the only place allowed to read the swap keys, each beside its default. */
final class RecordResolver
{
    public static function alert(): string
    {
        return (string) config('alerts.alert', Alert::class);
    }

    public static function healthCheck(): string
    {
        return (string) config('alerts.health-check', HealthCheck::class);
    }

    public static function silence(): string
    {
        return (string) config('alerts.silence-model', AlertSilence::class);
    }

    public static function historyRun(): string
    {
        return (string) config('alerts.history.model', HealthCheckRun::class);
    }
}
