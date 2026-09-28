<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use RoundlyConsulting\Testing\Assertions\Facades\DeclaredClass;
use RoundlyConsulting\Testing\Assertions\Facades\DeclaredClasses;
use RoundlyConsulting\Testing\Assertions\Facades\SourceReferences;
use RoundlyConsulting\Testing\Support\PhpFiles;
use RoundlyConsulting\Testing\Support\Psr4Directories;
use Throwable;

/**
 * One path into a package's behaviour: model convenience methods and model traits go
 * through the **manager** (`app(LikesManager::class)->like(...)`), never straight to an
 * action.
 *
 * ## The bug this pins
 *
 * `$user->like($post)` that calls `app(LikePost::class)->execute(...)` works — and is
 * invisible to `Likes::fake()`, which swaps the manager, not the action. A host test that
 * fakes the facade and drives the model method sees the real side effects run and every
 * `assertLiked()` fail, or worse, pass against a real write. The audit behind this found
 * fakes bypassed by traits exactly this way. Delegating to the manager keeps behaviour in one
 * place and lets the fake see every call.
 *
 * ## What is checked
 *
 * Three sets of subjects, none of which may reference anything under `{namespace}\Actions` —
 * imports included:
 *
 * 1. every class or trait under `{namespace}\Models`, `{namespace}\Concerns` and
 *    `{namespace}\Traits` (the host-model traits live here even when no package model uses
 *    them);
 * 2. every class **anywhere** under `{namespace}` that extends Eloquent's `Model` — so a
 *    package that groups its models by area (`Shops\Cart\Cart`, `Shops\Orders\Order`) is
 *    covered without naming each folder;
 * 3. every trait in `{namespace}` used by a subject above, recursively (a trait a trait
 *    uses counts), wherever it lives (`Support\TracksTotals`).
 *
 * Classes under `{namespace}\Actions` and `{namespace}\Testing` are never subjects: actions
 * compose actions, and a fake is the one place that stands in for them.
 *
 * Declarations are read from source tokens and resolved through the Composer PSR-4 map;
 * "extends `Model`" and "uses trait" come from reflection after autoload. A class that
 * cannot be autoloaded (a file declaring a name PSR-4 does not map, an integration whose
 * parent class is not installed) cannot be a working model, so it is skipped. References are
 * resolved from source tokens ({@see SourceReferences}), so an action named in a docblock is
 * not a violation and an aliased or grouped import still is.
 *
 * A non-model class outside the three namespaces (a `Support\Pruner` the manager holds) and a
 * trait no model uses (a helper trait that splits the manager) are **not** subjects: calling
 * actions is their job.
 *
 * ## Exemptions and vacuity
 *
 * `$ignoring` takes class or namespace names, matched exactly or as a namespace prefix (the
 * same shape Pest's `->ignoring()` accepts). Each entry is pinned twice: the preset registers
 * {@see ArchPresets::exemptionsExist()} (the name must exist), and this check fails an entry
 * that exempts **no violating class** — an exemption that outlived its violation is stale.
 *
 * If no subject is found at all — no model anywhere under the namespace and nothing in the
 * three namespaces — the check fails: a scan of nothing cannot catch a bypass, and a package
 * with no models or model traits should not call the preset.
 *
 * This is the assertion behind {@see ArchPresets::modelsGoThroughTheFacade()}.
 */
final class ModelsThroughFacade
{
    /**
     * The namespaces whose every declaration is scanned, relative to the package namespace.
     *
     * @var list<string>
     */
    public const array SCANNED = ['Models', 'Concerns', 'Traits'];

    /**
     * The namespaces never scanned as subjects, relative to the package namespace.
     *
     * @var list<string>
     */
    public const array EXCLUDED = ['Actions', 'Testing'];

    /**
     * @param  list<string>  $ignoring  class/namespace exemptions — pinned by {@see ArchExemptions}
     */
    public static function assert(string $namespace, array $ignoring = []): void
    {
        $namespace = trim($namespace, '\\');
        $actions = $namespace.'\\Actions\\';

        $subjects = self::subjects($namespace);

        Assert::assertNotSame(
            [],
            $subjects,
            "No Eloquent model anywhere under {$namespace}, and no class in {$namespace}\\Models, "
            ."{$namespace}\\Concerns or {$namespace}\\Traits, so there is nothing for modelsGoThroughTheFacade() "
            .'to guard and it cannot fail. A package with no models or model traits should not call it (and a '
            .'typo in the namespace lands here too).',
        );

        $references = [];
        $violations = [];

        foreach ($subjects as $class => [$file, $reason]) {
            $references[$file] ??= array_values(array_filter(
                SourceReferences::inFile($file, withImports: true),
                static fn (string $name): bool => str_starts_with($name.'\\', $actions),
            ));

            $used = $references[$file];

            if ($used !== []) {
                sort($used);
                $violations[$class] = implode(', ', $used)." ({$reason})";
            }
        }

        ksort($violations);

        $problems = [];
        $silencing = [];

        foreach ($violations as $class => $used) {
            $exemption = self::exemptionFor($class, $ignoring);

            if ($exemption !== null) {
                $silencing[$exemption] = true;

                continue;
            }

            $problems[] = "{$class} uses {$used}";
        }

        foreach ($ignoring as $entry) {
            if (! isset($silencing[$entry])) {
                $problems[] = "\$ignoring {$entry}: exempts no class that reaches into {$namespace}\\Actions, so it "
                    .'silences nothing. Remove it.';
            }
        }

        Assert::assertSame(
            [],
            $problems,
            "Models and model traits in {$namespace} must reach behaviour through the manager "
            ."(`app(<Domain>Manager::class)->…`), never through {$namespace}\\Actions directly — otherwise the "
            ."facade's fake() never sees the call:\n  - ".implode("\n  - ", $problems),
        );
    }

    /**
     * Every subject, keyed by class name, with the file it is declared in and why it is
     * scanned (for the failure message).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function subjects(string $namespace): array
    {
        $subjects = [];

        foreach (self::SCANNED as $segment) {
            foreach (self::declarations($namespace.'\\'.$segment) as $class) {
                $subjects[$class->name] ??= [$class->file, "in {$namespace}\\{$segment}"];
            }
        }

        foreach (self::declarations($namespace) as $class) {
            if ($class->kind === DeclaredClass::CLASS_KIND
                && ! self::excluded($class->name, $namespace)
                && self::isModel($class->name)) {
                $subjects[$class->name] ??= [$class->file, 'an Eloquent model'];
            }
        }

        $queue = array_keys($subjects);

        while ($queue !== []) {
            $user = array_shift($queue);

            foreach (self::traitsOf($user) as $trait => $file) {
                if (isset($subjects[$trait])
                    || ! str_starts_with($trait, $namespace.'\\')
                    || self::excluded($trait, $namespace)) {
                    continue;
                }

                $subjects[$trait] = [$file, "a trait used by {$user}"];
                $queue[] = $trait;
            }
        }

        return $subjects;
    }

    /**
     * @return list<DeclaredClass>
     */
    private static function declarations(string $namespace): array
    {
        $declared = [];

        foreach (Psr4Directories::for($namespace) as $dir) {
            foreach (PhpFiles::in($dir) as $file) {
                foreach (DeclaredClasses::inSource((string) file_get_contents($file), $file) as $class) {
                    $declared[] = $class;
                }
            }
        }

        return $declared;
    }

    private static function excluded(string $class, string $namespace): bool
    {
        foreach (self::EXCLUDED as $segment) {
            if (str_starts_with($class, $namespace.'\\'.$segment.'\\')) {
                return true;
            }
        }

        return false;
    }

    private static function isModel(string $class): bool
    {
        try {
            return class_exists($class) && is_subclass_of($class, Model::class);
        } catch (Throwable) {
            // Declared but unloadable (a parent class that is not installed): not a working model.
            return false;
        }
    }

    /**
     * The traits a class or trait uses directly, with the file each is declared in.
     *
     * @return array<string, string>
     */
    private static function traitsOf(string $class): array
    {
        try {
            if (! class_exists($class) && ! trait_exists($class)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $traits = [];

        foreach ((new ReflectionClass($class))->getTraits() as $trait) {
            $traits[$trait->getName()] = (string) $trait->getFileName();
        }

        return $traits;
    }

    /**
     * @param  list<string>  $ignoring
     */
    private static function exemptionFor(string $class, array $ignoring): ?string
    {
        foreach ($ignoring as $entry) {
            $name = trim($entry, '\\');

            if ($class === $name || str_starts_with($class, $name.'\\')) {
                return $entry;
            }
        }

        return null;
    }
}
