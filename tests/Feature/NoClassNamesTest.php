<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;

uses(MinimalPackageTestCase::class);

// ---------------------------------------------------------------------------
// Green: what a reference resource renders by default.
// ---------------------------------------------------------------------------

it('passes on a reference that holds only the key and the basename', function (mixed $payload): void {
    expect($payload)->toExposeNoClassNames();
})->with([
    'array' => [['id' => 1, 'type' => 'User']],
    'json string' => ['{"id":1,"type":"User"}'],
    'nested' => [['data' => ['author' => ['id' => 7, 'type' => 'User'], 'body' => 'a/b\\n', 'tags' => ['x', 'y']]]],
    'resource' => [new JsonResource(['id' => 1, 'type' => 'User'])],
    'json response' => [new JsonResponse(['author' => ['id' => 1, 'type' => 'User']])],
    'test response' => [TestResponse::fromBaseResponse(new JsonResponse(['id' => 1, 'type' => 'User']))],
    'scalars only' => [['id' => 1, 'active' => true, 'score' => 1.5, 'deleted_at' => null]],
]);

it('passes through the static entry point', function (): void {
    Assert::exposesNoClassNames(['id' => 1, 'type' => 'User']);

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Red: a class name anywhere a client can read it.
// ---------------------------------------------------------------------------

it('fails on a namespaced class name', function (mixed $payload, string $where): void {
    expect(fn () => expect($payload)->toExposeNoClassNames())
        ->toThrow(AssertionFailedError::class, $where);
})->with([
    'array value' => [['id' => 1, 'type' => 'App\Models\User'], 'App\Models\User'],
    'json string' => ['{"id":1,"type":"App\\\\Models\\\\User"}', 'App\Models\User'],
    'nested path' => [['data' => ['author' => ['id' => 1, 'type' => 'App\Models\User']]], 'data.author.type'],
    'list item' => [['data' => [['id' => 1], ['id' => 2, 'type' => 'App\Models\User']]], 'data.1.type'],
    'leading backslash' => [['owner' => '\App\Models\User'], 'App\Models\User'],
    'inside prose' => [['message' => 'No query results for model [App\Models\User] 5'], 'App\Models\User'],
    'escaped twice' => [['payload' => '{"type":"App\\\\Models\\\\User"}'], 'App\\\\Models\\\\User'],
    // Built at runtime: the escape is the point, so it must reach json_decode() as written.
    'unicode-escaped json' => [str_replace('#', chr(92), '{"type":"App#u005cModels#u005cUser"}'), 'App\Models\User'],
    'as a key' => [['App\Models\User' => 1], 'App\Models\User'],
    'resource' => [new JsonResource(['id' => 1, 'type' => 'App\Models\User']), 'App\Models\User'],
    'json response' => [new JsonResponse(['type' => 'App\Models\User']), 'App\Models\User'],
    'test response' => [TestResponse::fromBaseResponse(new JsonResponse(['type' => 'App\Models\User'])), 'App\Models\User'],
]);

it('names a morph type column holding a class name', function (): void {
    expect(fn () => expect(['commentable_type' => 'App\Models\Post', 'commentable_id' => 3])->toExposeNoClassNames())
        ->toThrow(AssertionFailedError::class, 'commentable_type');
});

it('fails on a *_type key holding a class without a namespace', function (): void {
    // A global class has no backslash to give it away; a morph column holding one is still a
    // class name on the wire.
    expect(fn () => expect(['subject_type' => 'ArrayObject'])->toExposeNoClassNames())
        ->toThrow(AssertionFailedError::class, 'subject_type');
});

it('lets a *_type key hold a morph alias', function (): void {
    expect(['subject_type' => 'post', 'payment_type' => 'card'])->toExposeNoClassNames();
});

it('lists every leak, not only the first', function (): void {
    expect(fn () => Assert::exposesNoClassNames(['a' => 'App\Models\User', 'b' => ['c' => 'App\Models\Team']]))
        ->toThrow(AssertionFailedError::class, 'App\Models\Team');
});

// ---------------------------------------------------------------------------
// Never green over nothing.
// ---------------------------------------------------------------------------

it('never passes on empty input', function (mixed $payload): void {
    expect(fn () => expect($payload)->toExposeNoClassNames())
        ->toThrow(AssertionFailedError::class, 'nothing to check');
})->with([
    'empty string' => [''],
    'blank string' => ['   '],
    'empty array' => [[]],
    'empty json object' => ['{}'],
    'empty json list' => ['[]'],
    'json null' => ['null'],
    'empty resource' => [new JsonResource([])],
    'empty response' => [new JsonResponse([])],
    'no-content response' => [new JsonResponse(null, 204)],
]);

it('refuses a string that is not json', function (): void {
    expect(fn () => expect('{"id": 1')->toExposeNoClassNames())
        ->toThrow(InvalidArgumentException::class, 'JSON');
});

it('refuses a subject it cannot read', function (mixed $subject): void {
    expect(fn () => expect($subject)->toExposeNoClassNames())
        ->toThrow(InvalidArgumentException::class);
})->with([
    'int' => [42],
    'object' => [new stdClass],
]);
