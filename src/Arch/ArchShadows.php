<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The reach of an arch exemption, measured — because Pest matches exemptions by **string
 * prefix, not class identity**.
 *
 * `pest-plugin-arch/src/Blueprint.php:103` is literally:
 *
 * ```php
 * if (str_starts_with($object->name, $exclude)) { continue 2; }
 * ```
 *
 * So exempting one class silently exempts every class whose fully-qualified name *starts
 * with the same characters*. `...\Stripe\Stripe` also silences `...\Stripe\StripeClient`;
 * `...\Metrics\Metrics` also silences `...\Metrics\MetricsManager`. Nobody wrote that
 * down, nothing reports it, and the rule simply stops applying to a class its author
 * still believes is covered.
 *
 * This is the {@see ArchExemptions} defect inverted. That one catches an exemption that
 * silences **nothing** (a typo, or one that outlived its code). This one catches an
 * exemption that silences **too much**. Both produce the same end state — an assertion
 * that cannot fail on real breakage — and this direction is the quieter of the two,
 * because the suite stays green and the exemption list still reads correct.
 *
 * ## Found by biting the preset, not by reading it
 *
 * purchases un-finalled `StripeClient` on purpose and `finalByDefault` stayed **green**:
 * the neighbouring `Stripe::class` exemption was covering for it. Two of the three classes
 * in that shadow were the exact ones the package had just decided to close.
 *
 * ## Why this restores coverage instead of just declaring the hole
 *
 * The obvious fix — "fail unless the shadow set is declared" — was measured against the
 * fleet first and rejected. 12 packages carry 28 shadowed classes, and **27 of them are
 * already final**: they satisfy the rule, and the shadow is inert. Demanding a declaration
 * would have failed 12 packages to describe 1 real bug, and the declaration itself buys
 * nothing — a declared shadow is still a class the rule no longer polices.
 *
 * So the shadowed classes are simply **re-checked here**, directly, by reflection. The
 * coverage Pest drops is put back rather than documented:
 *
 *  - a shadowed class that satisfies the rule → green, no ceremony, no declaration;
 *  - a shadowed class that violates it → **red**, naming the class and the exemption
 *    that hid it — which is exactly the bite that used to pass;
 *  - a shadowed class you genuinely want exempt → name it in `$ignoring`. It is then
 *    exactly-exempt, visible in review, and rot-checked by {@see ArchExemptions}.
 *
 * That sweep found `Metrics\MetricsManager` shipping non-final under a green
 * `finalByDefault` — a live violation nobody could see.
 *
 * ## Namespace exemptions are left alone, on purpose
 *
 * A namespace prefix is a documented, deliberate use of `->ignoring()` — Pest supports it
 * precisely so a subtree can be excluded, and {@see ArchExemptions} already accepts it as
 * live. Banning prefixes outright would break that legitimate use and reject a correct
 * exemption for being what it is.
 *
 * The distinction is drawn from the exemption itself rather than from a declaration: an
 * exemption that **names a class** intends to exempt that one class, so anything else it
 * reaches is an accident. An exemption that names a **namespace** intends to exempt a
 * subtree, so what it reaches is the point. No package has to describe its own shadows for
 * this to be true, which is why nothing here needs a `$declared` list.
 */
final class ArchShadows
{
    /**
     * The classes an exemption list silences **without naming them**.
     *
     * @param  list<string>  $exemptions
     * @return array<class-string, string> shadowed class => the exemption that hides it
     */
    public static function shadowed(string $namespace, array $exemptions): array
    {
        $named = array_map(static fn (string $e): string => ltrim($e, '\\'), $exemptions);

        $shadowed = [];

        foreach (self::concreteClassesIn($namespace) as $class) {
            // Exactly exempt: an argued, visible, rot-checked decision. Not our business.
            if (in_array($class, $named, true)) {
                continue;
            }

            foreach ($named as $exemption) {
                // Only a CLASS-form exemption can shadow by accident. A namespace-form one
                // is a deliberate subtree exclusion — see the class docblock.
                if (! self::isClass($exemption)) {
                    continue;
                }

                if ($class !== $exemption && str_starts_with($class, $exemption)) {
                    $shadowed[$class] = $exemption;

                    break;
                }
            }
        }

        ksort($shadowed);

        return $shadowed;
    }

    /**
     * Re-apply {@see ArchPresets::finalByDefault()} to the classes Pest's prefix matching
     * dropped: every shadowed class must be final.
     *
     * @param  list<string>  $exemptions
     */
    public static function assertShadowedClassesAreFinal(string $namespace, array $exemptions): void
    {
        $open = [];

        foreach (self::shadowed($namespace, $exemptions) as $class => $exemption) {
            if (! (new ReflectionClass($class))->isFinal()) {
                $open[] = $class.' (hidden by the exemption '.$exemption.')';
            }
        }

        Assert::assertSame(
            [],
            $open,
            "These classes are not final, and `finalByDefault` cannot see them: \n  - "
            .implode("\n  - ", $open)
            ."\nPest matches arch exemptions by string PREFIX, not class identity "
            .'(pest-plugin-arch Blueprint.php:103), so an exemption also silences every class '
            ."whose name starts with it — including these, which nobody exempted.\n"
            .'Either make them final, or, if they are genuinely meant to stay open, name each '
            .'one explicitly in $ignoring so the decision is visible and rot-checked.',
        );
    }

    private static function isClass(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
    }

    /**
     * Every concrete class under the namespace — the population `finalByDefault` speaks
     * about. Abstracts, interfaces, traits and enums are excluded for the same reason the
     * preset excludes them: `abstract final` is a PHP fatal, so they can never satisfy the
     * ban and flagging one would be a false positive by construction.
     *
     * @return list<class-string>
     */
    private static function concreteClassesIn(string $namespace): array
    {
        $namespace = ltrim($namespace, '\\');

        $classes = [];

        // No scope filter here on purpose: candidateFiles() builds every name from the
        // namespace itself, so it is the single place scope is decided. A second check would
        // be unreachable, and an unreachable guard is a claim nothing can verify.
        foreach (self::candidateFiles($namespace) as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isEnum() || $reflection->isInterface()) {
                continue;
            }

            $classes[] = $class;
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    /**
     * The class names Pest's arch layer would see for this namespace.
     *
     * This deliberately **mirrors** `ObjectsRepository::directoriesByNamespace()` rather
     * than inventing its own discovery: the population this check speaks about has to be
     * the population the preset actually policed, or it reports classes the rule was never
     * applied to. Pest resolves a namespace **downward only** — it finds the PSR-4 root(s)
     * that *contain* the namespace and walks the matching sub-directory. It does not union
     * sibling roots.
     *
     * That distinction is load-bearing, and was verified rather than assumed: this package
     * maps `RoundlyConsulting\Testing\` to `src` and `RoundlyConsulting\Testing\Tests\` to
     * `tests`. `expect('RoundlyConsulting\Testing')` scans **only `src`**, which is why its
     * 23 non-final test-support classes do not fail `finalByDefault`. A scan that unioned
     * every root under the namespace would flag them as shadowed and be wrong — the rule
     * never covered them, so no coverage was lost.
     *
     * The one intentional divergence: matching is anchored to a namespace **separator**
     * (`Foo\Bar` matches `Foo\Bar` or `Foo\Bar\...`, never `Foo\Barn`). Pest's bare
     * `str_starts_with` can pair a prefix with a mid-segment namespace and build a nonsense
     * path; it finds no files either way, so this only narrows to what could really match.
     *
     * @return list<string>
     */
    private static function candidateFiles(string $namespace): array
    {
        $names = [];

        foreach (self::psr4Prefixes() as $prefix => $directories) {
            $root = rtrim($prefix, '\\');

            if ($namespace !== $root && ! str_starts_with($namespace, $root.'\\')) {
                continue;
            }

            $relative = str_replace('\\', '/', ltrim(substr($namespace, strlen($root)), '\\'));

            foreach ($directories as $directory) {
                $target = rtrim($directory.'/'.$relative, '/');

                // A namespace can also be satisfied by a single same-named file, exactly as
                // Pest's `$fileOrDirectory.'.php'` branch allows.
                if (is_file($target.'.php')) {
                    $names[] = $namespace;
                }

                if (! is_dir($target)) {
                    continue;
                }

                foreach (self::phpFilesIn($target) as $file) {
                    $suffix = substr($file->getPathname(), strlen($target) + 1);

                    $names[] = $namespace.'\\'.str_replace('/', '\\', substr($suffix, 0, -4));
                }
            }
        }

        return $names;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function psr4Prefixes(): array
    {
        $prefixes = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            /** @var array<string, list<string>> $loaderPrefixes */
            $loaderPrefixes = $loader->getPrefixesPsr4();

            foreach ($loaderPrefixes as $prefix => $directories) {
                $prefixes[$prefix] = $directories;
            }
        }

        return $prefixes;
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var iterable<SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
