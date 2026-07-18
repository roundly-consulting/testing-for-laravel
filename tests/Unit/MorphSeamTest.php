<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\MorphSeam;

$fixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/morph-seam/'.$path;

/**
 * The bite: a migration that hardcodes a bigint morph id via raw `$table->morphs()` goes
 * red, and the failure names the offending file and call so a developer can find it.
 */
it('reds on a raw morphs() call and names the migration', function () use ($fixture): void {
    expect(fn () => MorphSeam::assert($fixture('raw')))
        ->toThrow(AssertionFailedError::class, '0001_01_01_000000_create_likes_table.php: morphs()');
});

/**
 * The seam itself must never trip its own guard: a migration that reaches every morph
 * column through `morphKey()` is exactly what P1 shipped, and stays green.
 */
it('passes when morph columns go through the morphKey seam', function () use ($fixture): void {
    MorphSeam::assert($fixture('seam'));
});

/**
 * Token-aware, not a substring grep: a docblock and a string literal both mentioning
 * `morphs(` are prose and data, not calls, and must not be a false red. This is the
 * distinction that caught media #27 for the config scraper.
 */
it('passes when the only morphs() mentions are a docblock and a string literal', function () use ($fixture): void {
    MorphSeam::assert($fixture('docblock'));
});

/**
 * Migrations-but-no-morphs: a real package that simply has no polymorphic columns
 * passes — it scanned real files and found no violation. Vacuity-safe, not vacuous.
 */
it('passes on a package with migrations but no morph columns', function () use ($fixture): void {
    MorphSeam::assert($fixture('no-morphs'));
});

/**
 * The arrow-and-paren discrimination, end to end: a static `Post::morphs(...)` call and a
 * bare `$config->morphs` property read both contain the word `morphs` reached differently,
 * and neither is the Blueprint method call this bans — so both stay green.
 */
it('passes on static calls and property reads that merely contain the word morphs', function (): void {
    $dir = sys_get_temp_dir().'/morph-seam-'.uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/0001_create_widgets_table.php', <<<'PHP'
        <?php

        return new class
        {
            public function up($config): void
            {
                Post::morphs('author');
                $ignored = $config->morphs;
            }
        };
        PHP);

    try {
        MorphSeam::assert($dir);
    } finally {
        unlink($dir.'/0001_create_widgets_table.php');
        rmdir($dir);
    }
});

/**
 * Non-vacuous #1: a directory that exists but holds no scannable migration file cannot
 * have caught anything, so it fails rather than reporting a hollow green.
 */
it('reds on a directory with zero scannable migration files', function () use ($fixture): void {
    expect(fn () => MorphSeam::assert($fixture('empty')))
        ->toThrow(AssertionFailedError::class, 'No migration files were scanned');
});

/**
 * Non-vacuous #2: a missing directory fails outright — a package with no migrations must
 * not adopt this preset.
 */
it('reds on a missing directory', function (): void {
    expect(fn () => MorphSeam::assert('/nope/not/here'))
        ->toThrow(AssertionFailedError::class, 'Migrations directory does not exist');
});
