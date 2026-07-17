<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\LiteralDirTestCase;

uses(LiteralDirTestCase::class);

/**
 * The regression pin for the loader's assertion bookkeeping.
 *
 * migrationDirectoryFor() verified a literal migration directory with
 * Assert::assertDirectoryExists(). That is a *passing* assertion, and
 * defineDatabaseMigrations() runs per test — so the loader charged +1 assertion to every
 * test in the suite. A test written `->throwsNoExceptions()` declares
 * expectNotToPerformAssertions(), so PHPUnit called it RISKY, and failOnRisky="true"
 * reds the whole run. 11 packages write tests that way.
 */
it('costs a test that performs no assertions nothing', function (): void {
    // The suite under this test loads its migrations from a literal directory. If the
    // loader charges its own precondition to the test, this goes RISKY (not failed) —
    // "not expected to perform assertions but performed 1 assertion" — and failOnRisky
    // turns that into a red CI on correct code.
})->throwsNoExceptions();

it('loads the migrations it was pointed at, without asserting to do it', function (): void {
    // Guard the guard: costing zero assertions is trivially achievable by not loading
    // anything. The table must actually be there.
    expect(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('posts'))->toBeTrue();
});
