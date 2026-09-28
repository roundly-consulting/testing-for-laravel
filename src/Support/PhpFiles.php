<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every `.php` file under a directory, recursively, in a stable (sorted) order — so a
 * failure message lists offenders the same way on every machine.
 */
final class PhpFiles
{
    /**
     * @return list<string>
     */
    public static function in(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
