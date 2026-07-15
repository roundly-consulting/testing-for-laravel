<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use ReflectionMethod;
use RoundlyConsulting\Testing\Concerns\SwapsConfiguredModels;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * Proves a package honours a configured model swap by *driving the real flow* and
 * checking the concrete class of every model it produces — the retrofit's single
 * biggest class of bug (12+ entries).
 *
 * Two things make this bite where a naive test does not:
 *
 *  1. **Boot order is load-bearing.** A provider hangs its observers, event listeners
 *     and relationship wiring on whatever `config('pkg.model')` names *at boot*. So the
 *     swap must be set BEFORE the providers boot (via `configBeforeBoot()` /
 *     {@see SwapsConfiguredModels}). If the caller
 *     forgot and swapped after boot, `config($configKey)` still reads the subclass but
 *     the listeners are on the packaged class — the exact shape that masked media #28.
 *     This assertion fails fast when `config($configKey) !== $subclass`, so a post-boot
 *     swap can never look like a pass.
 *  2. **`instanceof` is not enough.** permissions #31: a helper doing `static::query()
 *     ->create(...)` from inside the packaged model creates the row as the *packaged*
 *     class, so the host's model events never fire — yet the object still passes
 *     `instanceof Subclass`. This asserts the **concrete class** (`$model::class`) of
 *     every returned model equals `$subclass`, and — when the subclass uses the shipped
 *     {@see CountsCreations} trait — that at least one `created` event actually landed
 *     on it. "Counting events is the only way to prove the row was really created as the
 *     host's class."
 */
final class ModelSwap
{
    /**
     * @param  string  $configKey  e.g. 'media.media_model'
     * @param  class-string  $subclass  the host subclass, set into config BEFORE boot by the caller
     * @param  Closure  $exercise  drives the real flow and returns the Model|iterable<Model> it produced
     */
    public static function assert(string $configKey, string $subclass, Closure $exercise): void
    {
        $configured = config($configKey);

        Assert::assertSame(
            $subclass,
            $configured,
            "config('{$configKey}') is not {$subclass} — the model swap was not applied before boot. Set it in "
            .'configBeforeBoot() / swapModel() so the providers hang their observers and listeners on the host '
            .'subclass; a post-boot swap leaves them on the packaged class and this proof would be meaningless.',
        );

        $countsCreations = in_array(CountsCreations::class, class_uses_recursive($subclass), true);

        if ($countsCreations) {
            self::invokeStatic($subclass, 'resetCreationCount');
        }

        $models = self::normaliseModels($exercise());

        $seen = 0;

        foreach ($models as $model) {
            Assert::assertInstanceOf(
                Model::class,
                $model,
                'The model-swap exercise must return Eloquent models; got '.get_debug_type($model).'.',
            );

            $concrete = $model::class;

            Assert::assertSame(
                $subclass,
                $concrete,
                "A model returned by the exercise is a {$concrete}, not the configured {$subclass}. instanceof is "
                .'not enough: a helper resolving static::query() to the packaged class creates the row as the wrong '
                .'class, so the host subclass never fires its model events. The seam is being bypassed.',
            );

            $seen++;
        }

        Assert::assertGreaterThan(
            0,
            $seen,
            'The model-swap exercise returned no models, so nothing was checked — that is a vacuous pass. Return the '
            .'Model (or iterable of Models) the flow actually produced.',
        );

        if ($countsCreations) {
            Assert::assertGreaterThanOrEqual(
                1,
                (int) self::invokeStatic($subclass, 'creationCount'),
                "No row was created as {$subclass} during the exercise. The returned object may look right, but "
                .'counting created-events is the only proof the row was really persisted as the host subclass — zero '
                .'here means the seam created it as the packaged class.',
            );
        }
    }

    /**
     * @return iterable<Model>
     */
    private static function normaliseModels(mixed $result): iterable
    {
        if ($result instanceof Model) {
            return [$result];
        }

        if (is_iterable($result)) {
            return $result;
        }

        Assert::fail(
            'The model-swap exercise must return a Model or an iterable of Models; got '.get_debug_type($result).'.',
        );
    }

    private static function invokeStatic(string $class, string $method): mixed
    {
        return (new ReflectionMethod($class, $method))->invoke(null);
    }
}
