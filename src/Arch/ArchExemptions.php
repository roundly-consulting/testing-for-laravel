<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Composer\Autoload\ClassLoader;
use FilesystemIterator;
use PHPUnit\Framework\Assert;
use SplFileInfo;

/**
 * Rot-proofing for an arch preset's exemption list: an entry that silences nothing must
 * **fail**, not be quietly dropped.
 *
 * Pest's `->ignoring(...)` takes plain strings and never checks them, so
 * `->ignoring(Types\Metric::class)` — where the real class is `Facades\Metric` — silences
 * nothing and says nothing. The ban it was meant to relax then quietly applies to a class
 * that was never exempted, or (worse) the exemption outlives the code it excused and the
 * ban has a hole nobody can see. `::class` on a non-existent class is not an error in PHP:
 * it resolves to a string at compile time, so the typo is invisible.
 *
 * This is the standard the config contract already holds `allowUnread` / `allowUnshipped`
 * to — a stale entry there fails — applied to arch exemptions.
 *
 * An entry is **live** when it names a real class/interface/trait/enum, or a namespace
 * under which at least one PHP file exists. Both forms are legitimate in `ignoring()`, so
 * checking only for classes would reject a namespace exemption for being what it is.
 *
 * Given the namespace the rule scans (`$within`), a live entry must also **land in it** — a
 * real class from another namespace exempts nothing there. Whether the matched class actually
 * *violates* the rule is not checked: that would need each rule's own verdict per class, and an
 * entry that names an already-compliant class is inert rather than a hole (it becomes one only
 * when the class stops complying — the reason to keep lists minimal).
 */
final class ArchExemptions
{
    /**
     * @param  list<string>  $exemptions
     * @param  string|null  $within  the namespace the rule scans; when given, every entry must
     *                               also match at least one object under it
     */
    public static function assert(array $exemptions, ?string $within = null): void
    {
        $stale = [];

        foreach ($exemptions as $exemption) {
            if (! self::isLive($exemption)) {
                $stale[] = $exemption;
            }
        }

        sort($stale);

        Assert::assertSame(
            [],
            $stale,
            'These arch exemptions silence nothing — they name neither a real class nor a namespace that '
            .'holds one: '.implode(', ', $stale).'. An exemption that matches nothing is either a typo '
            .'(`Types\Metric` for `Facades\Metric`) or has outlived the code it excused; both leave the '
            .'ban applying where you think it does not. Remove it, or fix the name.',
        );

        if ($within === null) {
            return;
        }

        $objects = array_keys(ArchTargets::in($within));
        $outside = [];

        foreach ($exemptions as $exemption) {
            $name = ltrim($exemption, '\\');
            $matches = array_filter($objects, static fn (string $object): bool => str_starts_with($object, $name));

            if ($matches === []) {
                $outside[] = $exemption;
            }
        }

        sort($outside);

        Assert::assertSame(
            [],
            $outside,
            "These arch exemptions exist but match nothing under {$within}, the namespace the rule scans: "
            .implode(', ', $outside).'. They exempt nothing — most likely the class lives in another '
            .'namespace than the one this preset checks. Remove them, or point them at the class the rule '
            .'actually reports.',
        );
    }

    private static function isLive(string $exemption): bool
    {
        $name = ltrim($exemption, '\\');

        if ($name === '') {
            return false;
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return true;
        }

        return self::namespaceHasFiles($name);
    }

    /**
     * Whether the name resolves, through the running Composer autoloader's PSR-4 map, to a
     * directory that actually holds PHP files — i.e. it is a real namespace to ignore
     * rather than a misspelled class.
     */
    private static function namespaceHasFiles(string $namespace): bool
    {
        foreach (self::psr4Prefixes() as $prefix => $directories) {
            if (! str_starts_with($namespace.'\\', $prefix)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($namespace, strlen($prefix)));

            foreach ($directories as $directory) {
                $candidate = rtrim($directory.'/'.$relative, '/');

                if (is_dir($candidate) && self::holdsPhpFiles($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function psr4Prefixes(): array
    {
        $prefixes = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                $prefixes[$prefix] = $directories;
            }
        }

        return $prefixes;
    }

    private static function holdsPhpFiles(string $directory): bool
    {
        $iterator = new FilesystemIterator(
            $directory,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            // A sub-directory counts: a namespace exemption may cover only nested
            // namespaces and hold no files of its own.
            if ($entry->isDir() || strtolower($entry->getExtension()) === 'php') {
                return true;
            }
        }

        return false;
    }
}
