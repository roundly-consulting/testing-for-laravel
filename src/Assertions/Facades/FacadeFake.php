<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionNamedType;

/**
 * A facade's `fake()` must be real, must return a **subtype of the root**, and must actually
 * take over — for the facade *and* for dependency injection.
 *
 * ## The two bugs this pins
 *
 * - **A fake that is not a subtype of the accessor type.** `fake()` swaps the fake into the
 *   container under the accessor, so every class that constructor-injects the manager now
 *   receives the fake — and if the fake does not extend the manager (or implement the
 *   contract), that is a `TypeError` the moment the host's code runs under the fake. The
 *   audit found exactly this: fakes that worked through the facade and crashed through DI.
 * - **A fake that only exists in the docblock.** `@method static TeamsFake fake()` makes the
 *   IDE happy and does nothing: `__callStatic` forwards `fake()` to the manager, which has no
 *   such method.
 *
 * ## What is checked
 *
 * Statically: the facade declares a real `public static function fake()` whose declared
 * return type is a class R, and R is a subtype of the accessor type. Then, in the booted
 * application: calling `fake()` returns an R, the facade root **is** that instance, and
 * `app(<accessor>)` resolves that **same** instance (`static::swap()` does both).
 *
 * ## Side-effect free
 *
 * The facade root and the container entry for the accessor are restored afterwards, so this
 * composes with the other facade expectations in any order — a later
 * `toReachEveryAction()` still sees the real implementation, not the fake.
 */
final class FacadeFake
{
    public static function assert(string $facade): void
    {
        $subject = FacadeSubject::resolve($facade);
        $facadeClass = new ReflectionClass($subject->facade);
        $short = $subject->shortFacade();

        if (! $facadeClass->hasMethod('fake')) {
            $documented = preg_match('/@method\s+static\s+(?:\S+\s+)?fake\s*\(/', (string) $facadeClass->getDocComment()) === 1;

            Assert::fail($documented
                ? "{$subject->facade}::fake() exists only as a `@method` docblock line. A docblock cannot build or "
                    ."swap a fake — `__callStatic` forwards the call to {$subject->accessor}, which has no fake(). "
                    ."Declare `public static function fake(): {$short}Fake` on the facade: build the fake, "
                    .'`static::swap()` it, return it.'
                : "{$subject->facade} has no fake(). A facade with side effects ships `public static function "
                    ."fake(): {$short}Fake` that builds the fake and `static::swap()`s it; a pure package with "
                    .'nothing to fake should not call toBeFakeable().');
        }

        $method = $facadeClass->getMethod('fake');

        Assert::assertTrue(
            $method->isPublic() && $method->isStatic(),
            "{$subject->facade}::fake() must be `public static` — it is the facade's entry point, called as {$short}::fake().",
        );

        $type = $method->getReturnType();
        $described = match (true) {
            $type === null => 'none',
            $type instanceof ReflectionNamedType => ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '').$type->getName(),
            default => 'a union or intersection type',
        };

        Assert::assertTrue(
            $type instanceof ReflectionNamedType
                && ! $type->isBuiltin()
                && ! $type->allowsNull()
                && (class_exists($type->getName()) || interface_exists($type->getName())),
            "{$subject->facade}::fake() must declare its fake class as the return type (got: {$described}). "
            ."Declare `fake(): {$short}Fake` — the type is what proves, before anything runs, that the fake "
            .'can stand in for the root.',
        );

        /** @var ReflectionNamedType $type */
        $fakeClass = $type->getName();

        Assert::assertTrue(
            is_a($fakeClass, $subject->accessor, true),
            "{$fakeClass} is not a subtype of the accessor type {$subject->accessor}. fake() swaps it into the "
            ."container under {$subject->accessor}, so every class that constructor-injects {$subject->accessor} "
            .'receives the fake and dies with a TypeError. Make the fake extend the manager (or implement the '
            .'contract).',
        );

        $app = Facade::getFacadeApplication();

        Assert::assertTrue(
            $app instanceof Container && $app->bound('app'),
            "toBeFakeable() calls {$short}::fake() for real, so it needs the booted application. Bind this test "
            .'to your PackageTestCase-based TestCase (`uses(TestCase::class)`).',
        );

        /** @var Container $app */
        $accessor = $subject->accessor;
        $previous = $app->resolved($accessor) && $app->isShared($accessor) ? $app->make($accessor) : null;

        try {
            $fake = $method->invoke(null);

            Assert::assertInstanceOf(
                $fakeClass,
                $fake,
                "{$short}::fake() returned ".get_debug_type($fake).", not the {$fakeClass} it declares.",
            );

            Assert::assertSame(
                $fake,
                $subject->facade::getFacadeRoot(),
                "{$short}::fake() returned a fake but did not install it: the facade root is still something "
                .'else. Call `static::swap($fake)` before returning it.',
            );

            Assert::assertSame(
                $fake,
                $app->make($accessor),
                "{$short}::fake() installed the fake behind the facade only: app({$accessor}) still resolves the "
                .'real implementation, so constructor-injected code bypasses the fake. Use `static::swap($fake)`, '
                .'which rebinds the container too.',
            );
        } finally {
            $subject->facade::clearResolvedInstance($accessor);

            if ($previous !== null) {
                $app->instance($accessor, $previous);
            } else {
                $app->forgetInstance($accessor);
            }
        }
    }
}
