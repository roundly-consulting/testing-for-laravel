<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use PHPUnit\Framework\Assert;
use RoundlyConsulting\Testing\Assertions\Facades\DeclaredClasses;
use RoundlyConsulting\Testing\Assertions\Facades\SourceReferences;
use RoundlyConsulting\Testing\Support\PhpFiles;
use RoundlyConsulting\Testing\Support\Psr4Directories;

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
 * Every class or trait under `{namespace}\Models`, `{namespace}\Concerns` and
 * `{namespace}\Traits` (resolved through the Composer PSR-4 map, recursively) must not
 * reference anything under `{namespace}\Actions` — imports included. References are resolved
 * from source tokens ({@see SourceReferences}), so an action named in a docblock is not a
 * violation and an aliased or grouped import still is.
 *
 * ## Exemptions and vacuity
 *
 * `$ignoring` takes class or namespace names, matched exactly or as a namespace prefix (the
 * same shape Pest's `->ignoring()` accepts). Each entry is pinned twice: the preset registers
 * {@see ArchPresets::exemptionsExist()} (the name must exist), and this check fails an entry
 * that exempts **no violating class** — an exemption that outlived its violation is stale.
 *
 * If none of the three namespaces holds a class, the check fails: a scan of nothing cannot
 * catch a bypass, and a package with no models or model traits should not call the preset.
 *
 * This is the assertion behind {@see ArchPresets::modelsGoThroughTheFacade()}.
 */
final class ModelsThroughFacade
{
    /**
     * The namespaces scanned, relative to the package namespace.
     *
     * @var list<string>
     */
    public const array SCANNED = ['Models', 'Concerns', 'Traits'];

    /**
     * @param  list<string>  $ignoring  class/namespace exemptions — pinned by {@see ArchExemptions}
     */
    public static function assert(string $namespace, array $ignoring = []): void
    {
        $namespace = trim($namespace, '\\');
        $actions = $namespace.'\\Actions\\';

        $scanned = [];
        $violations = [];

        foreach (self::SCANNED as $segment) {
            foreach (Psr4Directories::for($namespace.'\\'.$segment) as $dir) {
                foreach (PhpFiles::in($dir) as $file) {
                    $source = (string) file_get_contents($file);
                    $classes = DeclaredClasses::inSource($source, $file);

                    if ($classes === []) {
                        continue;
                    }

                    $used = array_values(array_filter(
                        SourceReferences::inSource($source, withImports: true),
                        static fn (string $name): bool => str_starts_with($name.'\\', $actions),
                    ));

                    sort($used);

                    foreach ($classes as $class) {
                        $scanned[$class->name] = true;

                        if ($used !== []) {
                            $violations[$class->name] = $used;
                        }
                    }
                }
            }
        }

        Assert::assertNotSame(
            [],
            $scanned,
            "Neither {$namespace}\\Models, {$namespace}\\Concerns nor {$namespace}\\Traits holds a class, so there "
            .'is nothing for modelsGoThroughTheFacade() to guard and it cannot fail. A package with no models or '
            .'model traits should not call it (and a typo in the namespace lands here too).',
        );

        ksort($violations);

        $problems = [];
        $silencing = [];

        foreach ($violations as $class => $used) {
            $exemption = self::exemptionFor($class, $ignoring);

            if ($exemption !== null) {
                $silencing[$exemption] = true;

                continue;
            }

            $problems[] = "{$class} uses ".implode(', ', $used);
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
