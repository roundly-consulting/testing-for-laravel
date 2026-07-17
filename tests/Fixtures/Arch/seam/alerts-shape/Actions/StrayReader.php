<?php

declare(strict_types=1);

namespace Fixture\AlertsShape\Actions;

/**
 * A competing resolution path outside the seam, on the three keys whose shape the inference
 * cannot see: no `model` segment at all, or a hyphen where it tests for an underscore.
 */
final class StrayReader
{
    public function run(): array
    {
        return [
            config('alerts.alert'),
            config('alerts.health-check'),
            config('alerts.silence-model'),
        ];
    }
}
