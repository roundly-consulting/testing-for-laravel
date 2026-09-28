<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use Throwable;

/**
 * Every host-facing action must be reachable **through the facade** — as a flat method on the
 * manager, or through a sub-accessor or handle the manager hands back.
 *
 * ## The bug this pins
 *
 * The audit behind this found ~70 host-facing actions no facade could reach: callable only
 * from a controller, a command or a model trait, or from nothing at all. A host that wants
 * the operation has to discover the action class and resolve it by hand, and a facade
 * `fake()` never sees the call. "Only a controller can reach it" is a gap; an action nothing
 * reaches is either exposed or deleted.
 *
 * ## How reachability is decided
 *
 * 1. **Actions** are collected by tokens from every PHP file under `$actionsDir`. Abstract
 *    classes, interfaces, traits, enums and classes whose docblock says `@internal` (building
 *    blocks only other actions, jobs or listeners call) are not host-facing and are skipped.
 * 2. **The surface** is walked from the accessor type — plus the class `app(<accessor>)`
 *    resolves to when an application is booted, plus any `$via` class — along native return
 *    types, inside the package namespace ({@see FacadeSurface}). Models, DTOs, events, enums,
 *    exceptions, the fake and actions themselves are not surface: a model convenience method
 *    that calls an action directly is exactly the bypass this exists to report.
 * 3. An action is **reachable** when its class name is referenced in code (not comments) in
 *    any surface file ({@see SourceReferences}).
 *
 * ## `$via` — a helper the manager holds, not returns
 *
 * The walk follows return types, so a helper held in a constructor property and never
 * returned is invisible to it. Name such a class in `$via` to add it as an extra root. A
 * `$via` entry must exist, and must make at least one action reachable that is not reachable
 * without it — otherwise it silences nothing and fails as stale.
 *
 * ## Non-vacuous
 *
 * A missing directory fails, and so does a directory holding no host-facing action: a check
 * over nothing cannot fail, so it must not pass. Every `$except` entry must name a host-facing
 * action under `$actionsDir` that really is unreachable — a stale exemption fails.
 */
final class ActionReach
{
    /**
     * Namespace segments whose classes are data or plumbing, never facade surface.
     */
    private const array NOT_SURFACE = ['Actions', 'Models', 'DataTransferObjects', 'Events', 'Enums', 'Exceptions'];

    /**
     * @param  list<string>  $except  unreachable actions deliberately tolerated (rot-checked)
     * @param  list<string>  $via  extra surface roots: helpers the manager holds but never returns
     */
    public static function assert(string $facade, string $actionsDir, array $except = [], array $via = []): void
    {
        $subject = FacadeSubject::resolve($facade);

        Assert::assertDirectoryExists(
            $actionsDir,
            "Actions directory does not exist: {$actionsDir}. A reachability check over a missing directory "
            .'would pass over nothing.',
        );

        $declared = DeclaredClasses::in($actionsDir);
        $actions = [];

        foreach ($declared as $class) {
            if ($class->isHostFacing()) {
                $actions[$class->name] = $class;
            }
        }

        Assert::assertNotSame(
            [],
            $actions,
            $declared === []
                ? "No class is declared under {$actionsDir}. A reachability check over nothing cannot fail — point it "
                    .'at the real actions directory.'
                : 'Every class under '.$actionsDir.' is abstract, an interface/trait/enum, or @internal, so there is '
                    .'no host-facing action to reach and this check cannot fail. A package with no host-facing '
                    .'action should not call toReachEveryAction().',
        );

        foreach ($via as $class) {
            Assert::assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class),
                "\$via entry {$class} does not exist — it adds nothing to the surface.",
            );
        }

        $notes = [];
        $roots = [$subject->accessor];
        $concrete = self::concreteRoot($subject, $notes);

        if ($concrete !== null) {
            $roots[] = $concrete;
        }

        $declaredNames = array_fill_keys(array_map(static fn (DeclaredClass $class): string => $class->name, $declared), true);

        $skip = static fn (string $class): bool => isset($declaredNames[$class])
            || self::inNonSurfaceNamespace($subject, $class)
            || enum_exists($class)
            || is_subclass_of($class, Model::class)
            || is_a($class, Throwable::class, true)
            || $subject->isFake($class);

        [$surface, $reachable] = self::reach($subject, [...$roots, ...$via], $skip, $actions);

        $problems = [];

        foreach ($via as $class) {
            $without = array_values(array_filter($via, static fn (string $other): bool => $other !== $class));
            [, $reachableWithout] = self::reach($subject, [...$roots, ...$without], $skip, $actions);

            if (array_diff($reachable, $reachableWithout) === []) {
                $problems[] = "\$via {$class}: makes no action reachable that is not reachable without it, so it "
                    .'silences nothing. Remove it.';
            }
        }

        $unreachable = array_values(array_diff(array_keys($actions), $reachable));
        $excepted = [];

        foreach ($except as $entry) {
            $name = ltrim($entry, '\\');

            if (! isset($actions[$name])) {
                $problems[] = "\$except {$entry}: not a host-facing action under {$actionsDir}, so the exemption "
                    .'silences nothing. Remove it.';
            } elseif (! in_array($name, $unreachable, true)) {
                $problems[] = "\$except {$entry}: reachable from the facade already, so the exemption silences "
                    .'nothing. Remove it.';
            } else {
                $excepted[$name] = true;
            }
        }

        foreach ($unreachable as $action) {
            if (! isset($excepted[$action])) {
                $problems[] = "{$action} is not reachable from {$subject->facade} — expose it through the facade "
                    .'(flat method or sub-accessor) or mark it `@internal` if it is a building block.';
            }
        }

        sort($surface);

        Assert::assertSame(
            [],
            $problems,
            "{$subject->facade} does not reach every host-facing action under {$actionsDir}:\n  - "
            .implode("\n  - ", $problems)
            ."\nSurface scanned: ".implode(', ', $surface).'.'
            .($notes === [] ? '' : "\nNote: ".implode("\nNote: ", $notes)),
        );
    }

    /**
     * Walk the surface from $roots and return it together with the actions it references.
     *
     * @param  list<string>  $roots
     * @param  Closure(string): bool  $skip
     * @param  array<string, DeclaredClass>  $actions
     * @return array{0: list<class-string>, 1: list<string>}
     */
    private static function reach(FacadeSubject $subject, array $roots, Closure $skip, array $actions): array
    {
        $surface = FacadeSurface::walk($subject, $roots, $skip);
        $referenced = [];

        foreach ($surface as $class) {
            $file = (new ReflectionClass($class))->getFileName();

            if ($file !== false && ! isset($referenced[$file])) {
                $referenced[$file] = SourceReferences::inFile($file);
            }
        }

        $names = $referenced === [] ? [] : array_merge(...array_values($referenced));

        return [$surface, array_values(array_intersect(array_keys($actions), $names))];
    }

    /**
     * The class the booted application resolves the accessor to, looking through an active
     * fake to the real implementation it extends. Null when no application is booted, the
     * accessor is not bound, or nothing in the package stands behind the fake.
     *
     * @param  list<string>  $notes
     */
    private static function concreteRoot(FacadeSubject $subject, array &$notes): ?string
    {
        $app = Facade::getFacadeApplication();

        if (! $app instanceof Container || ! $app->bound('app')) {
            if (interface_exists($subject->accessor)) {
                $notes[] = "{$subject->accessor} is an interface and no application is booted, so the class it "
                    .'resolves to was not scanned. Bind this test to your PackageTestCase-based TestCase, or pass '
                    .'the implementation through $via.';
            }

            return null;
        }

        if (! $app->bound($subject->accessor)) {
            return null;
        }

        try {
            $instance = $app->make($subject->accessor);
        } catch (Throwable $e) {
            Assert::fail("Resolving app({$subject->accessor}) threw: {$e->getMessage()}");
        }

        if (! is_object($instance)) {
            return null;
        }

        $class = $instance::class;

        if (! $subject->isFake($class)) {
            return $subject->owns($class) ? $class : null;
        }

        while ($class !== false && $subject->isFake($class)) {
            $class = get_parent_class($class);
        }

        if ($class === false || ! $subject->owns($class)) {
            $notes[] = "app({$subject->accessor}) currently resolves to the fake ".$instance::class.', and no '
                .'package class stands behind it, so the real implementation was not scanned. Assert '
                .'reachability before faking.';

            return null;
        }

        return $class;
    }

    /**
     * Whether the class sits under a data/plumbing segment *below* the package namespace —
     * so a package that is itself named `Events` or `Models` is not skipped wholesale.
     */
    private static function inNonSurfaceNamespace(FacadeSubject $subject, string $class): bool
    {
        $relative = '\\'.substr($class, strlen($subject->namespace) + 1);

        foreach (self::NOT_SURFACE as $segment) {
            if (str_contains($relative, '\\'.$segment.'\\')) {
                return true;
            }
        }

        return false;
    }
}
