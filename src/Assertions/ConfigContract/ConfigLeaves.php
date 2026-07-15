<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\ConfigContract;

use PHPUnit\Framework\Assert;

/**
 * Flattens a shipped config file into its dotted **leaf** keys under a prefix.
 *
 * A leaf is any value that is not a non-empty associative array: scalars, nulls,
 * lists, and empty arrays are read wholesale and count as one key. Nested
 * associative arrays are recursed into. So `['rp' => ['id' => 'x']]` under prefix
 * `passkeys` yields `passkeys.rp.id`, while `['aaguid_allow_list' => []]` yields
 * `passkeys.aaguid_allow_list` — the empty array is a single leaf, read as a whole.
 *
 * These leaves are the right-hand side of the reverse contract: every one of them
 * must be read somewhere in the source, or it is dead weight (the alerts / media /
 * query-builder class of shipped-but-unread keys).
 */
final class ConfigLeaves
{
    /**
     * @return list<string> the dotted leaf keys of the config file, each prefixed
     */
    public static function forFile(string $configPath, string $prefix): array
    {
        Assert::assertFileExists($configPath, "Config file does not exist: {$configPath}");

        $config = require $configPath;

        Assert::assertIsArray($config, "Config file {$configPath} must return an array.");

        $leaves = [];
        self::flatten($config, $prefix, $leaves);

        return array_values(array_unique($leaves));
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $leaves
     */
    private static function flatten(array $node, string $path, array &$leaves): void
    {
        if ($node === [] || ! self::isAssociative($node)) {
            $leaves[] = $path;

            return;
        }

        foreach ($node as $key => $value) {
            $childPath = $path.'.'.$key;

            if (is_array($value)) {
                self::flatten($value, $childPath, $leaves);

                continue;
            }

            $leaves[] = $childPath;
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function isAssociative(array $node): bool
    {
        return array_keys($node) !== range(0, count($node) - 1);
    }
}
