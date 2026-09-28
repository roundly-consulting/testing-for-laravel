<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use RoundlyConsulting\Testing\Assertions\Migrations\OrderingRejection;

/**
 * A QueryException shaped exactly as the engine's PDO driver reports it.
 *
 * @param  array{0: string, 1: int|null, 2: string}  $errorInfo
 */
function engineRefusal(array $errorInfo): QueryException
{
    $pdo = new PDOException("SQLSTATE[{$errorInfo[0]}]: {$errorInfo[2]}");
    $pdo->errorInfo = $errorInfo;

    return new QueryException('probe', 'create table …', [], $pdo);
}

it('recognises the postgres ordering refusals', function (string $state): void {
    expect(OrderingRejection::recognises(engineRefusal([$state, 7, 'ERROR: relation does not exist'])))->toBeTrue();
})->with(['undefined table' => '42P01', 'undefined column' => '42703', 'invalid foreign key' => '42830', 'fk violation' => '23503']);

it('recognises the mysql ordering refusals by SQLSTATE and by driver code', function (string $state, int $code): void {
    expect(OrderingRejection::recognises(engineRefusal([$state, $code, 'Failed to open the referenced table'])))->toBeTrue();
})->with([
    'table not found' => ['42S02', 1146],
    'column not found' => ['42S22', 1054],
    'referenced table missing' => ['HY000', 1824],
    'cannot add foreign key' => ['HY000', 1215],
    'errno 150' => ['HY000', 1005],
    'missing parent index' => ['HY000', 1822],
]);

it('recognises the sqlite ordering refusals by message', function (string $message): void {
    expect(OrderingRejection::recognises(engineRefusal(['HY000', 1, $message])))->toBeTrue();
})->with(['no such table: widgets', 'no such column: author_id', 'FOREIGN KEY constraint failed', 'foreign key mismatch - "posts" referencing "users"']);

it('does not count a refusal that has nothing to do with order', function (array $errorInfo): void {
    expect(OrderingRejection::recognises(engineRefusal($errorInfo)))->toBeFalse();
})->with([
    'pgsql connection refused' => [['08006', 7, 'connection to server at "127.0.0.1", port 1 failed: Connection refused']],
    'pgsql syntax error' => [['42601', 7, 'syntax error at or near "this"']],
    'pgsql permission denied' => [['42501', 7, 'permission denied for schema public']],
    'mysql access denied' => [['28000', 1045, "Access denied for user 'root'"]],
    'sqlite syntax error' => [['HY000', 1, 'near "this": syntax error']],
    'sqlite not a database' => [['HY000', 26, 'file is not a database']],
]);

it('falls back to the exception code when the driver left no errorInfo', function (): void {
    $exception = new QueryException('probe', 'select 1', [], new RuntimeException('relation "x" does not exist', 0));

    expect(OrderingRejection::recognises($exception))->toBeFalse();
});
