<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Database;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;
use RoundlyConsulting\Testing\PackageTestCase;
use UnitEnum;

/**
 * Everything a migration can read, as one hash: two tests with the same signature get the
 * same schema from a fresh migrate, so the second may reuse the first's.
 *
 * In it: the connection (driver, worker database, every option), the migration directories in
 * order, the loaded service providers in order, the whole config repository, the schema
 * builder's static defaults (string length, morph key type, time precision) and the test
 * case's {@see PackageTestCase::schemaSignatureExtras()}.
 *
 * Objects compare by class — an enum by case — so two closures, or two instances of one
 * class, look alike. Anything else a migration reads (an env var, a static flag, an object's
 * state) belongs in `schemaSignatureExtras()`.
 *
 * @internal Part of {@see KeptSchema}.
 */
final class SchemaSignature
{
    /**
     * @param  list<string>  $migrationDirectories
     * @param  list<string>  $providers
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $extras
     */
    public static function of(Connection $connection, array $migrationDirectories, array $providers, array $config, array $extras): string
    {
        return hash('xxh128', serialize([
            $connection->getName(),
            self::normalised($connection->getConfig()),
            $migrationDirectories,
            $providers,
            self::normalised($config),
            [Builder::$defaultStringLength, Builder::$defaultMorphKeyType, Builder::$defaultTimePrecision],
            self::normalised($extras),
        ]));
    }

    private static function normalised(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalised(...), $value);
        }

        if ($value instanceof UnitEnum) {
            return 'enum:'.$value::class.'::'.$value->name;
        }

        return is_object($value) ? 'object:'.$value::class : $value;
    }
}
