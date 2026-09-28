<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * A facade under test, resolved once: the facade class, the root type its accessor names,
 * the package namespace that root lives in, and the fake class its `fake()` declares.
 *
 * Every facade assertion starts here, so all three fail the same way on the same broken
 * input — above all on a **string accessor**. `getFacadeAccessor(): 'teams'` hides the root
 * type from dependency injection, from static analysis and from every check in this
 * namespace: nothing can compare a docblock against a container key, and a host that injects
 * the manager gets a different object than the facade. The fix is always the same — return
 * the manager (or its contract) class-string.
 *
 * The **package namespace** is the root's first two namespace segments
 * (`RoundlyConsulting\Teams\TeamsManager` → `RoundlyConsulting\Teams`). It is what separates
 * the package's own API from vendor API it inherits, and bounds the facade surface.
 */
final readonly class FacadeSubject
{
    /**
     * @param  class-string<Facade>  $facade
     * @param  class-string  $accessor
     * @param  class-string|null  $fake
     */
    private function __construct(
        public string $facade,
        public string $accessor,
        public string $namespace,
        public ?string $fake,
    ) {}

    public static function resolve(string $facade): self
    {
        Assert::assertTrue(class_exists($facade), "Facade {$facade} does not exist.");

        Assert::assertTrue(
            is_subclass_of($facade, Facade::class),
            "{$facade} is not a facade: it does not extend ".Facade::class.'.',
        );

        /** @var class-string<Facade> $facade */
        $accessor = self::accessorOf($facade);

        return new self($facade, $accessor, self::packageNamespace($accessor), self::declaredFake($facade));
    }

    /**
     * Whether a class belongs to the package (lives under its namespace).
     */
    public function owns(string $class): bool
    {
        return $this->namespace !== '' && str_starts_with(ltrim($class, '\\'), $this->namespace.'\\');
    }

    /**
     * Whether a class is the facade's fake (or a subclass of it).
     */
    public function isFake(string $class): bool
    {
        return $this->fake !== null && is_a($class, $this->fake, true);
    }

    public function shortFacade(): string
    {
        return (new ReflectionClass($this->facade))->getShortName();
    }

    /**
     * @param  class-string<Facade>  $facade
     * @return class-string
     */
    private static function accessorOf(string $facade): string
    {
        try {
            $accessor = (new ReflectionMethod($facade, 'getFacadeAccessor'))->invoke(null);
        } catch (Throwable $e) {
            Assert::fail("{$facade}::getFacadeAccessor() threw instead of naming its root: {$e->getMessage()}");
        }

        Assert::assertIsString(
            $accessor,
            "{$facade}::getFacadeAccessor() returns an object instead of a class-string. Return the "
            .'manager (or its contract) class-string so the root type is visible to DI and to these checks.',
        );

        $accessor = ltrim($accessor, '\\');

        Assert::assertTrue(
            class_exists($accessor) || interface_exists($accessor),
            "{$facade}::getFacadeAccessor() returns '{$accessor}', which is not a class or interface. A string "
            .'container key hides the root type from dependency injection, from static analysis and from '
            .'every facade check — return the manager (or its contract) class-string instead, e.g. '
            .'`return TeamsManager::class;`, and bind that class in the service provider.',
        );

        /** @var class-string $accessor */
        return $accessor;
    }

    private static function packageNamespace(string $accessor): string
    {
        $segments = explode('\\', $accessor);
        array_pop($segments);

        return implode('\\', array_slice($segments, 0, 2));
    }

    /**
     * The class the facade's own `fake()` declares it returns, or null when there is no
     * such method or its return type names no class.
     *
     * @param  class-string<Facade>  $facade
     * @return class-string|null
     */
    private static function declaredFake(string $facade): ?string
    {
        if (! method_exists($facade, 'fake')) {
            return null;
        }

        $type = (new ReflectionMethod($facade, 'fake'))->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        return class_exists($name) || interface_exists($name) ? $name : null;
    }
}
