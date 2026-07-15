<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use FilesystemIterator;
use PHPUnit\Framework\Assert;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The permissions #34 pin, generalized: a package with a config-swappable model must
 * resolve that model **through its seam** and nowhere else.
 *
 * Two ways the seam gets bypassed, both caught here from source tokens:
 *
 *   1. `static::query()` / `self::query()` / `new static` inside a model or helper.
 *      Late static binding resolves to the class the code *named*, not the one the host
 *      *configured* — so a "findOrCreate" helper silently creates and queries rows as
 *      the packaged class, and authorization for a host's own subclass reads empty. The
 *      correct seam resolves the configured class-string first (`self::class()::query()`)
 *      and is therefore never flagged.
 *
 *   2. the `'<pkg>.<model-key>'` config literal appearing in a file outside the seam
 *      directory. Only the seam may read the swap key; a stray literal anywhere else is
 *      a second, competing resolution path that will drift from the seam.
 *
 * This is the assertion behind {@see ArchPresets::modelsResolveThroughSeam()}.
 */
final class ModelSeam
{
    /**
     * A config key that swaps a model: a dotted key whose final segment is `model`,
     * `models`, or ends in `_model` (e.g. `shops.shop_model`, `passkeys.model`,
     * `permissions.models.permission`). Tight enough not to trip on arbitrary strings.
     */
    private const MODEL_KEY = '/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)+$/';

    public static function assert(string $srcDir, string $seamDir = 'Support'): void
    {
        Assert::assertDirectoryExists($srcDir, "Source directory does not exist: {$srcDir}");

        $lateBinding = [];
        $strayLiterals = [];

        foreach (self::phpFiles($srcDir) as $file) {
            $tokens = self::meaningfulTokens((string) file_get_contents($file));

            if (self::usesLateStaticResolution($tokens)) {
                $lateBinding[] = self::relative($srcDir, $file);
            }

            if (! self::isInSeam($srcDir, $file, $seamDir)) {
                foreach (self::modelKeyLiterals($tokens) as $literal) {
                    $strayLiterals[] = self::relative($srcDir, $file).": '{$literal}'";
                }
            }
        }

        sort($lateBinding);
        sort($strayLiterals);

        Assert::assertSame(
            [],
            $lateBinding,
            'These files resolve a model through late static binding (static::query() / self::query() / '
            .'new static) instead of the configured seam, so a host subclass swap is ignored: '
            .implode(', ', $lateBinding).'. Resolve the configured class-string first.',
        );

        Assert::assertSame(
            [],
            $strayLiterals,
            "These files read a swappable-model config literal outside the '{$seamDir}' seam, creating a "
            .'competing resolution path: '.implode(', ', $strayLiterals)
            .". Read the key only through the {$seamDir} seam.",
        );
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $tokens
     */
    private static function usesLateStaticResolution(array $tokens): bool
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            [$id, $text] = $tokens[$i];

            // `new static`
            if ($id === T_NEW) {
                $next = $tokens[$i + 1] ?? null;

                if ($next !== null && $next[0] === T_STATIC) {
                    return true;
                }
            }

            // `static::query(` or `self::query(`
            if ($text === '::') {
                $prev = $tokens[$i - 1] ?? null;
                $method = $tokens[$i + 1] ?? null;
                $paren = $tokens[$i + 2] ?? null;

                $onStaticOrSelf = $prev !== null
                    && ($prev[0] === T_STATIC || ($prev[0] === T_STRING && $prev[1] === 'self'));

                if ($onStaticOrSelf
                    && $method !== null && $method[0] === T_STRING && $method[1] === 'query'
                    && $paren !== null && $paren[1] === '(') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: int|null, 1: string}>  $tokens
     * @return list<string>
     */
    private static function modelKeyLiterals(array $tokens): array
    {
        $literals = [];

        foreach ($tokens as [$id, $text]) {
            if ($id !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $value = self::stringValue($text);

            if (preg_match(self::MODEL_KEY, $value) === 1 && self::hasModelSegment($value)) {
                $literals[] = $value;
            }
        }

        return array_values(array_unique($literals));
    }

    private static function hasModelSegment(string $value): bool
    {
        foreach (explode('.', $value) as $segment) {
            if ($segment === 'model' || $segment === 'models' || str_ends_with($segment, '_model')) {
                return true;
            }
        }

        return false;
    }

    private static function isInSeam(string $srcDir, string $file, string $seamDir): bool
    {
        $relative = self::relative($srcDir, $file);

        return str_starts_with($relative, $seamDir.'/');
    }

    private static function relative(string $srcDir, string $file): string
    {
        return ltrim(str_replace(rtrim($srcDir, '/'), '', $file), '/');
    }

    /**
     * Tokenize and drop comments/docblocks/whitespace so a mention of `static::query()`
     * or a config key in prose never trips the guard — only real code does.
     *
     * @return list<array{0: int|null, 1: string}>
     */
    private static function meaningfulTokens(string $source): array
    {
        $tokens = [];

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                    continue;
                }

                $tokens[] = [$token[0], $token[1]];

                continue;
            }

            $tokens[] = [null, $token];
        }

        return $tokens;
    }

    private static function stringValue(string $raw): string
    {
        $quote = $raw[0] ?? '';
        $inner = substr($raw, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }

    /**
     * @return list<string>
     */
    private static function phpFiles(string $dir): array
    {
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
