<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

/**
 * A facade's `@method static` docblock is its **only** declaration of the API: `__callStatic`
 * forwards anything, so an IDE, PHPStan and a reader know what `Teams::` offers only from
 * those lines. They drift silently — the audit behind this found two facades with **zero**
 * `@method` lines over a real manager, and others documenting methods that had been renamed.
 * This pins the docblock to the root type it fronts, in both directions.
 *
 * ## What is compared
 *
 * - **The root** is the class or interface `getFacadeAccessor()` names (a string key fails —
 *   see {@see FacadeSubject}). Its public methods must each be documented, except the
 *   constructor, magic `__*` methods, methods marked `@internal`, and **vendor API**: a method
 *   declared outside the package namespace, or whose name a vendor ancestor, interface or
 *   trait declares (`Illuminate\Support\Manager::driver()`, `Macroable::macro()`, an
 *   implemented `getDefaultDriver()`). Names in `$except` are skipped too.
 * - **Each documented method** must exist — on the root, as a real public static on the
 *   facade itself (e.g. `fake()`), or on the fake class the facade's `fake()` declares (for
 *   `assert*()` helpers) — and document exactly as many parameters as the real method takes.
 *   The count comes from a depth-aware scan ({@see MethodTags}), so generics, array shapes,
 *   callable types and array defaults cannot miscount.
 *
 * ## Non-vacuous
 *
 * The facade must be `final`. A docblock with no `@method` line fails, a root with no
 * documentable method fails (a facade over nothing proves nothing), a name documented twice
 * fails, and every `$except` entry must name a real, documentable, **undocumented** root
 * method — an exemption that silences nothing is stale and fails like one.
 */
final class FacadeDocblock
{
    /**
     * @param  list<string>  $except  root method names deliberately left off the docblock
     */
    public static function assert(string $facade, array $except = []): void
    {
        $subject = FacadeSubject::resolve($facade);
        $facadeClass = new ReflectionClass($subject->facade);
        $root = new ReflectionClass($subject->accessor);

        Assert::assertTrue(
            $facadeClass->isFinal(),
            "{$subject->facade} is not final. A facade is sugar over {$subject->accessor} with no logic of "
            .'its own — declare it `final` so nothing can subclass it into a second, divergent API.',
        );

        $required = self::requiredMethods($root, $subject);

        Assert::assertNotSame(
            [],
            $required,
            "{$subject->accessor} has no public method for {$subject->facade} to document (constructor, magic, "
            .'@internal and vendor-inherited methods do not count). A facade over nothing proves nothing — '
            .'point getFacadeAccessor() at the real manager.',
        );

        $tags = MethodTags::parse((string) $facadeClass->getDocComment());

        Assert::assertFalse(
            $tags->isEmpty(),
            "{$subject->facade} has no `@method static` line in its class docblock, so its whole API is "
            .'invisible to IDEs, static analysis and readers. Document every public method of '
            ."{$subject->accessor}, starting with:\n  ".implode("\n  ", array_map(
                static fn (ReflectionMethod $method): string => self::suggestion($method),
                array_values($required),
            )),
        );

        $fake = $subject->fake === null ? null : new ReflectionClass($subject->fake);

        $problems = [];
        $documented = [];

        foreach ($tags->tags as $tag) {
            $key = strtolower($tag->name);

            if (isset($documented[$key])) {
                $problems[] = "{$tag->name}(): documented more than once.";

                continue;
            }

            $documented[$key] = $tag;

            if (! $tag->static) {
                $problems[] = "{$tag->name}(): written `@method` without `static`; a facade is called statically — "
                    .'write `@method static`.';
            }

            $real = self::realMethod($tag->name, $root, $facadeClass, $fake);

            if ($real === null) {
                $places = ["the root {$subject->accessor}", "{$subject->facade} itself"];

                if ($fake !== null) {
                    $places[] = "its fake {$fake->getName()}";
                }

                $problems[] = "{$tag->name}(): documented, but no such method exists on ".implode(' or ', $places)
                    .' — a phantom that fails at call time. Remove the line or fix the name.';

                continue;
            }

            $takes = $real->getNumberOfParameters();

            if ($takes !== $tag->parameters) {
                $problems[] = "{$tag->name}(): documents {$tag->parameters} parameter(s), but "
                    ."{$real->class}::{$real->name}() takes {$takes}. Expected: ".self::suggestion($real);
            }
        }

        foreach ($tags->unparseable as $line) {
            $problems[] = "unreadable tag `{$line}` — expected `@method static <return> name(<params>)`.";
        }

        $excepted = [];

        foreach ($except as $entry) {
            $key = strtolower($entry);
            $excepted[$key] = true;

            if (! isset($required[$key])) {
                $problems[] = "\$except '{$entry}': {$subject->accessor} has no documentable public method of that "
                    .'name, so the exemption silences nothing. Remove it.';
            } elseif (isset($documented[$key])) {
                $problems[] = "\$except '{$entry}': the method is documented anyway, so the exemption silences "
                    .'nothing. Remove it.';
            }
        }

        foreach ($required as $key => $method) {
            if (! isset($documented[$key]) && ! isset($excepted[$key])) {
                $problems[] = "{$method->name}(): public on {$subject->accessor} but undocumented. Add `"
                    .self::suggestion($method).'` (or mark the method `@internal` if hosts must not call it).';
            }
        }

        Assert::assertSame(
            [],
            $problems,
            "The `@method` docblock of {$subject->facade} does not match its root {$subject->accessor}:\n  - "
            .implode("\n  - ", $problems),
        );
    }

    /**
     * The root's public methods the docblock must cover, keyed by lower-case name.
     *
     * @param  ReflectionClass<object>  $root
     * @return array<string, ReflectionMethod>
     */
    private static function requiredMethods(ReflectionClass $root, FacadeSubject $subject): array
    {
        $required = [];

        foreach ($root->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || str_starts_with($method->name, '__')) {
                continue;
            }

            if (InternalTag::in((string) $method->getDocComment())) {
                continue;
            }

            if (self::isVendorMethod($method, $subject)) {
                continue;
            }

            $required[strtolower($method->name)] = $method;
        }

        return $required;
    }

    /**
     * A method is vendor API when it is declared outside the package, or when its **name**
     * comes from a vendor ancestor, interface or trait — an override of
     * `Manager::getDefaultDriver()` is still the framework's contract, not the package's API.
     */
    private static function isVendorMethod(ReflectionMethod $method, FacadeSubject $subject): bool
    {
        $declaring = $method->getDeclaringClass();

        if (! $subject->owns($declaring->getName())) {
            return true;
        }

        foreach (self::lineage($declaring) as $related) {
            if (! $subject->owns($related->getName()) && $related->hasMethod($method->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every parent, interface and (recursively) used trait of a class.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<ReflectionClass<object>>
     */
    private static function lineage(ReflectionClass $class): array
    {
        $lineage = array_values($class->getInterfaces());
        $pending = [$class];

        while ($pending !== []) {
            $current = array_shift($pending);

            foreach ($current->getTraits() as $trait) {
                $lineage[] = $trait;
                $pending[] = $trait;
            }

            $parent = $current->getParentClass();

            if ($parent !== false) {
                $lineage[] = $parent;
                $pending[] = $parent;
            }
        }

        return $lineage;
    }

    /**
     * Where a documented name really lives: the root first, then a public static on the facade
     * itself, then the fake. Null means a phantom.
     *
     * @param  ReflectionClass<object>  $root
     * @param  ReflectionClass<Facade>  $facade
     * @param  ReflectionClass<object>|null  $fake
     */
    private static function realMethod(string $name, ReflectionClass $root, ReflectionClass $facade, ?ReflectionClass $fake): ?ReflectionMethod
    {
        if ($root->hasMethod($name) && $root->getMethod($name)->isPublic()) {
            return $root->getMethod($name);
        }

        if ($facade->hasMethod($name) && $facade->getMethod($name)->isPublic() && $facade->getMethod($name)->isStatic()) {
            return $facade->getMethod($name);
        }

        if ($fake !== null && $fake->hasMethod($name) && $fake->getMethod($name)->isPublic()) {
            return $fake->getMethod($name);
        }

        return null;
    }

    /**
     * The `@method static` line that documents a real method — offered in every failure so
     * the fix is a paste, not a lookup.
     */
    private static function suggestion(ReflectionMethod $method): string
    {
        $parameters = array_map(
            static fn (ReflectionParameter $parameter): string => self::parameter($parameter),
            $method->getParameters(),
        );

        return '@method static '.self::type($method->getReturnType(), $method).' '
            .$method->name.'('.implode(', ', $parameters).')';
    }

    private static function parameter(ReflectionParameter $parameter): string
    {
        $hint = $parameter->hasType() ? self::type($parameter->getType(), $parameter->getDeclaringFunction()).' ' : '';
        $variadic = $parameter->isVariadic() ? '...' : '';
        $default = '';

        if ($parameter->isDefaultValueAvailable()) {
            $value = $parameter->getDefaultValue();

            $default = match (true) {
                $value === null => ' = null',
                $value === [] => ' = []',
                is_bool($value) => $value ? ' = true' : ' = false',
                is_int($value), is_float($value) => ' = '.$value,
                is_string($value) => ' = '.var_export($value, true),
                default => '',
            };
        }

        return $hint.$variadic.'$'.$parameter->getName().$default;
    }

    private static function type(?ReflectionType $type, object $scope): string
    {
        if ($type instanceof ReflectionNamedType) {
            $name = $type->getName();

            if (in_array($name, ['self', 'static'], true) && $scope instanceof ReflectionMethod) {
                $name = $scope->getDeclaringClass()->getName();
            }

            $name = $type->isBuiltin() ? $name : '\\'.$name;

            return $type->allowsNull() && ! in_array($type->getName(), ['mixed', 'null'], true) ? '?'.$name : $name;
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(static fn (ReflectionType $inner): string => self::type($inner, $scope), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map(static fn (ReflectionType $inner): string => self::type($inner, $scope), $type->getTypes()));
        }

        return 'mixed';
    }
}
