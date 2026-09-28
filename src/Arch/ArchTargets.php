<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use PHPUnit\Framework\Assert;
use ReflectionClass;
use RoundlyConsulting\Testing\Support\PhpFiles;
use RoundlyConsulting\Testing\Support\Psr4Directories;

/**
 * What a namespace-scoped arch preset actually checks — resolved the way Pest's arch layer
 * resolves it, through the running Composer PSR-4 map: a namespace becomes its directory
 * (every PHP file below it), a class name becomes its own file.
 *
 * ## Why the presets need it
 *
 * Pest's arch expectations pass over an empty set. `ArchPresets::strictTypes('App\Shopp')` —
 * one typo'd letter — resolves to no directory, checks nothing, and reports green, the same
 * as `finalByDefault()` and `noLocalCryptoPrimitives()` on it. That is exactly the vacuous
 * green this package exists to end, so each of those presets registers a companion case that
 * fails unless there is something left to check once `$ignoring` is applied.
 *
 * @internal
 */
final class ArchTargets
{
    /**
     * Every object under the namespace: fully-qualified name (derived from the PSR-4 path)
     * => file.
     *
     * @return array<string, string>
     */
    public static function in(string $namespace): array
    {
        $namespace = trim($namespace, '\\');
        $objects = [];

        foreach (Psr4Directories::for($namespace) as $directory) {
            foreach (PhpFiles::in($directory) as $file) {
                $relative = substr($file, strlen($directory) + 1, -4);
                $objects[$namespace.'\\'.str_replace('/', '\\', $relative)] = $file;
            }
        }

        // Pest accepts a single class as the target too, not only a namespace.
        if ($objects === [] && (class_exists($namespace) || interface_exists($namespace) || trait_exists($namespace))) {
            $file = (new ReflectionClass($namespace))->getFileName();

            if ($file !== false) {
                $objects[$namespace] = $file;
            }
        }

        ksort($objects);

        return $objects;
    }

    /**
     * Fail unless the preset has at least one object left to check after its exemptions — a
     * concrete class when `$concreteClassesOnly` (what `finalByDefault` speaks about), any
     * PHP file otherwise.
     *
     * Exemptions remove what Pest removes: every object whose name starts with the entry. The
     * one exception is `finalByDefault`, whose shadow recovery ({@see ArchShadows}) re-checks
     * the classes a class-form entry hides by prefix — there, a class-form entry removes only
     * the class it names.
     *
     * @param  list<string>  $ignoring  the preset's exemptions
     */
    public static function assertSomethingToCheck(string $namespace, array $ignoring, string $preset, bool $concreteClassesOnly = false): void
    {
        $objects = array_keys(self::in($namespace));

        Assert::assertNotSame(
            [],
            $objects,
            "`{$preset}` found nothing under {$namespace}: no directory in the Composer PSR-4 map holds that "
            .'namespace, so the preset would check an empty set and pass. Check the namespace for a typo.',
        );

        $checked = array_filter(
            $objects,
            static fn (string $object): bool => ! self::exempted($object, $ignoring, $concreteClassesOnly)
                && (! $concreteClassesOnly || self::isConcreteClass($object)),
        );

        Assert::assertNotSame(
            [],
            $checked,
            "`{$preset}` has nothing left to check under {$namespace}: every "
            .($concreteClassesOnly ? 'concrete class' : 'file')
            .' there is exempted by $ignoring, or there is none. A preset over an empty set passes vacuously — '
            .'point it at the namespace that holds the code, or drop the preset.',
        );
    }

    /**
     * @param  list<string>  $ignoring
     * @param  bool  $shadowsAreRechecked  a class-form entry exempts only the class it names
     */
    private static function exempted(string $object, array $ignoring, bool $shadowsAreRechecked): bool
    {
        foreach ($ignoring as $exemption) {
            $name = ltrim($exemption, '\\');
            $isClass = class_exists($name) || interface_exists($name) || trait_exists($name);

            if ($shadowsAreRechecked && $isClass ? $object === $name : str_starts_with($object, $name)) {
                return true;
            }
        }

        return false;
    }

    private static function isConcreteClass(string $name): bool
    {
        if (! class_exists($name)) {
            return false;
        }

        $class = new ReflectionClass($name);

        return ! $class->isAbstract() && ! $class->isInterface() && ! $class->isTrait() && ! $class->isEnum();
    }
}
