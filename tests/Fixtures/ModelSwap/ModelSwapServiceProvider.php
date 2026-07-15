<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\ModelSwap;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * A throwaway "package" whose model is configurable and whose observer is hung on the
 * *configured* class at boot — the exact shape the model-swap assertion protects.
 */
final class ModelSwapServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $config = $this->app->make('config');

        if ($config->get('model_swap.record_model') === null) {
            $config->set('model_swap.record_model', Record::class);
        }
    }

    public function boot(): void
    {
        $model = $this->app->make('config')->get('model_swap.record_model');

        if (is_string($model) && is_a($model, Model::class, true)) {
            $model::observe(RecordObserver::class);
        }
    }
}
