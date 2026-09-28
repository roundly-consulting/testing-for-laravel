<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Support;

use Composer\Autoload\ClassLoader;

/**
 * The directories a namespace maps to through the running Composer autoloader's PSR-4
 * table — how a preset that is handed a *namespace* finds the source it must scan, the
 * same way it resolves for a path-symlinked sibling, a VCS install and this package's
 * own `autoload-dev` fixtures.
 */
final class Psr4Directories
{
    /**
     * Existing directories only; a namespace no loader maps (or maps to a missing
     * directory) yields `[]`, which the caller must treat as "nothing to scan".
     *
     * @return list<string>
     */
    public static function for(string $namespace): array
    {
        $namespace = trim($namespace, '\\').'\\';
        $found = [];

        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            foreach ($loader->getPrefixesPsr4() as $prefix => $directories) {
                if (! str_starts_with($namespace, $prefix)) {
                    continue;
                }

                $relative = str_replace('\\', '/', substr($namespace, strlen($prefix)));

                foreach ($directories as $directory) {
                    $candidate = rtrim($directory.'/'.$relative, '/');

                    if (is_dir($candidate)) {
                        $found[] = realpath($candidate) ?: $candidate;
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
