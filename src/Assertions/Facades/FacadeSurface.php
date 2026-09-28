<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use Closure;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/**
 * The set of classes a host can reach **through a facade**: its root, plus everything the
 * root's public methods hand back — sub-accessors, model-scoped handles, and whatever *their*
 * public methods hand back, breadth-first.
 *
 * `Teams::for($team)->members()->add($user)` reaches `AddMember` through two hops
 * (`TeamsManager::for()` → `TeamHandle`, `TeamHandle::members()` → `MembersAccessor`); the
 * walk follows **native return types** (named, union, intersection; `self`/`static` = the
 * class itself), so that is found without anyone listing the hops.
 *
 * The walk stays inside the package namespace and skips what is data rather than API — the
 * caller's `$skip` decides (models, DTOs, events, enums, exceptions, the fake, and actions
 * themselves). In-package parent classes and used traits of every surface class join the
 * surface too: a manager's behaviour may live in a base class or a trait.
 */
final class FacadeSurface
{
    /**
     * @param  list<string>  $roots
     * @param  Closure(string): bool  $skip  whether a discovered class stays off the surface
     * @return list<class-string>
     */
    public static function walk(FacadeSubject $subject, array $roots, Closure $skip): array
    {
        $queue = $roots;
        $surface = [];

        while ($queue !== []) {
            $class = ltrim((string) array_shift($queue), '\\');

            if (isset($surface[$class]) || ! self::exists($class)) {
                continue;
            }

            /** @var class-string $class */
            $surface[$class] = true;
            $reflection = new ReflectionClass($class);

            $parent = $reflection->getParentClass();
            $related = $reflection->getTraitNames();

            if ($parent !== false) {
                $related[] = $parent->getName();
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                foreach (self::returnedClasses($method->getReturnType()) as $returned) {
                    $related[] = $returned;
                }
            }

            foreach ($related as $candidate) {
                if ($subject->owns($candidate) && self::exists($candidate) && ! $skip($candidate)) {
                    $queue[] = $candidate;
                }
            }
        }

        return array_keys($surface);
    }

    /**
     * @return list<string>
     */
    private static function returnedClasses(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $classes = [];

            foreach ($type->getTypes() as $inner) {
                array_push($classes, ...self::returnedClasses($inner));
            }

            return $classes;
        }

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return [];
        }

        // `self` / `static` / `parent` name the declaring class or its parent, which the walk
        // reaches anyway (the class itself, or through its parent chain).
        return in_array($type->getName(), ['self', 'static', 'parent'], true) ? [] : [$type->getName()];
    }

    private static function exists(string $class): bool
    {
        return class_exists($class) || interface_exists($class) || trait_exists($class);
    }
}
