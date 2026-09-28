<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Illuminate\Container\Container;
use PHPUnit\Framework\Assert;
use ReflectionClass;

/**
 * The deliberate tension with "everything final": a config-swappable model must NOT
 * be final, because the whole seam invites a host subclass. Shipping `final` on such a
 * model is a PHP fatal error the moment a host tries to swap it — it happened seven
 * times across the fleet under a green "all classes are final" arch test.
 *
 * This helper proves, per mapped model, both halves of the seam:
 *   1. the model is not `final` (a host can actually extend it);
 *   2. the config key it advertises defaults to the very model — a seam that defaults
 *      to something else is not really this model's seam.
 *
 * It is the assertion behind {@see ArchPresets::swappableModelsAreNotFinal()}, the
 * `expect($model)->toBeSwappableVia($configKey)` expectation and
 * `Assert::modelIsSwappableVia()`; all call in here so the check is identical however it is
 * reached. The config half needs the booted application — an arch file must be bound to the
 * package's TestCase.
 */
final class SwappableModels
{
    /**
     * @param  array<class-string, string>  $map  model class => the config key that swaps it
     */
    public static function assert(array $map): void
    {
        Assert::assertNotSame(
            [],
            $map,
            'swappableModelsAreNotFinal needs at least one [Model::class => config-key] pair; '
            .'an empty map asserts nothing and would pass vacuously.',
        );

        foreach ($map as $model => $configKey) {
            self::assertEntry($model, $configKey);
        }
    }

    /**
     * @param  class-string  $model
     */
    public static function assertEntry(string $model, string $configKey): void
    {
        Assert::assertTrue(
            class_exists($model),
            "Swappable model {$model} does not exist.",
        );

        Assert::assertFalse(
            (new ReflectionClass($model))->isFinal(),
            "Swappable model {$model} is declared `final`, but config '{$configKey}' invites a host "
            .'subclass — a final class cannot be extended, so the swap is a PHP fatal error. Drop `final`.',
        );

        // The config half reads the booted app's config. In a Pest file bound to no TestCase
        // there is none, and `config()` died with "Target class [config] does not exist".
        Assert::assertTrue(
            Container::getInstance()->bound('config'),
            "Checking that '{$configKey}' defaults to {$model} needs the booted application, and this test has "
            .'none. Bind the file to your PackageTestCase-based TestCase — `uses(TestCase::class)` in the file, '
            .'or `uses(TestCase::class)->in(\'Arch\')` in tests/Pest.php.',
        );

        $default = config($configKey);

        Assert::assertSame(
            $model,
            $default,
            "Config '{$configKey}' does not default to {$model} (got "
            .(is_string($default) ? "'{$default}'" : gettype($default))
            .'). A swappable-model seam must default to the very model it advertises.',
        );
    }
}
