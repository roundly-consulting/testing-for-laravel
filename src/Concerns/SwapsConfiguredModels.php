<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Concerns;

use Illuminate\Contracts\Foundation\Application;

/**
 * Swaps a package's configured model class *before providers boot*.
 *
 * Boot order is load-bearing: a provider hangs its observers and event listeners on
 * whatever class `config('pkg.model')` names at boot time. Swapping the model after
 * boot leaves those listeners on the packaged class, so the host's subclass silently
 * never fires its model events. The only correct place to swap is
 * `defineEnvironment()`, which Testbench runs before the providers register — this
 * trait collects the swaps and applies them there.
 */
trait SwapsConfiguredModels
{
    /** @var array<string, class-string> */
    private array $configuredModelSwaps = [];

    /**
     * Register a model swap to apply before boot, e.g.
     * `$this->swapModel('media.media_model', CustomMedia::class)`.
     *
     * @param  class-string  $model
     */
    protected function swapModel(string $configKey, string $model): void
    {
        $this->configuredModelSwaps[$configKey] = $model;
    }

    protected function applyConfiguredModelSwaps(Application $app): void
    {
        $config = $app->make('config');

        foreach ($this->configuredModelSwaps as $configKey => $model) {
            $config->set($configKey, $model);
        }
    }
}
