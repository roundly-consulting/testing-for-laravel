<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use RoundlyConsulting\Testing\Database\SchemaSignature;

/**
 * Two tests share a kept schema only when their signatures match, so every input a migration
 * can read must move it — and nothing else may.
 */
function signatureOf(array $overrides = [], string $database = ':memory:'): string
{
    $connection = new SQLiteConnection(new PDO('sqlite::memory:'), $database, '', ['driver' => 'sqlite', 'database' => $database, 'name' => 'testing']);

    $inputs = [
        'directories' => ['/pkg/database/migrations'],
        'providers' => ['Pkg\\PkgServiceProvider'],
        'config' => ['pkg' => ['key_type' => 'id']],
        'extras' => [],
        ...$overrides,
    ];

    return SchemaSignature::of($connection, $inputs['directories'], $inputs['providers'], $inputs['config'], $inputs['extras']);
}

enum SignatureKeyType
{
    case Uuid;
    case Ulid;
}

it('is stable for the same inputs', function (): void {
    expect(signatureOf())->toBe(signatureOf());
});

it('moves with every input a migration can read', function (array $overrides, string $database = ':memory:'): void {
    expect(signatureOf($overrides, $database))->not->toBe(signatureOf());
})->with([
    'the connection' => [[], '/tmp/other.sqlite'],
    'a migration directory' => [['directories' => ['/pkg/database/migrations', '/fixtures']]],
    'the directory order' => [['directories' => ['/fixtures', '/pkg/database/migrations']]],
    'a provider' => [['providers' => ['Pkg\\PkgServiceProvider', 'Other\\OtherServiceProvider']]],
    'a config value' => [['config' => ['pkg' => ['key_type' => 'uuid']]]],
    'an enum in config' => [['config' => ['pkg' => ['key_type' => SignatureKeyType::Uuid]]]],
    'the extras' => [['extras' => ['TENANCY' => 'on']]],
]);

it('tells enum cases apart', function (): void {
    expect(signatureOf(['config' => ['pkg' => SignatureKeyType::Uuid]]))
        ->not->toBe(signatureOf(['config' => ['pkg' => SignatureKeyType::Ulid]]));
});

it('compares other objects by class, closures included', function (): void {
    expect(signatureOf(['config' => ['pkg' => ['resolver' => fn (): string => 'a']]]))
        ->toBe(signatureOf(['config' => ['pkg' => ['resolver' => fn (): string => 'b']]]))
        ->and(signatureOf(['config' => ['pkg' => new ArrayObject]]))
        ->not->toBe(signatureOf(['config' => ['pkg' => new stdClass]]));
});

it('moves with the schema builder\'s static defaults', function (Closure $set, Closure $restore): void {
    $before = signatureOf();

    $set();

    try {
        expect(signatureOf())->not->toBe($before);
    } finally {
        $restore();
    }
})->with([
    'string length' => [fn () => Builder::defaultStringLength(191), fn () => Builder::defaultStringLength(255)],
    'morph key type' => [fn () => Builder::morphUsingUuids(), fn () => Builder::defaultMorphKeyType('int')],
    'time precision' => [fn () => Builder::defaultTimePrecision(6), fn () => Builder::defaultTimePrecision(0)],
]);
