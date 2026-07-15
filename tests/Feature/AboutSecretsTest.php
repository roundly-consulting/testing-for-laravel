<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Tests\Support\AboutSecretsTestCase;

uses(AboutSecretsTestCase::class);

function renderAbout(array $payload): void
{
    config()->set('about_fixture.payload', $payload);
}

// ---------------------------------------------------------------------------
// Green: the section renders its proof and leaks nothing.
// ---------------------------------------------------------------------------

it('passes when the section renders its proof and no secret', function (): void {
    renderAbout([
        'Sign-count policy' => 'enabled',
        'AAGUID allow-list' => '2 entries',
    ]);

    expect('testing')->toLeakNoSecrets(
        secrets: ['super-secret-signing-key', '/srv/acme/secrets'],
        mustRender: ['Sign-count policy', 'AAGUID allow-list'],
    );
});

it('ignores empty secret entries', function (): void {
    renderAbout(['Sign-count policy' => 'enabled']);

    expect('testing')->toLeakNoSecrets(
        secrets: ['', '   '],
        mustRender: ['Sign-count policy'],
    );
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('bites when the section leaks a secret', function (): void {
    renderAbout([
        'Sign-count policy' => 'enabled',
        'Signing key' => 'super-secret-signing-key',
    ]);

    expect(fn (): mixed => expect('testing')->toLeakNoSecrets(
        secrets: ['super-secret-signing-key'],
        mustRender: ['Sign-count policy'],
    ))->toThrow(AssertionFailedError::class);
});

it('bites on the positive proof when the section renders empty', function (): void {
    // The section renders, but not the string we require — the negative checks must
    // never be reached, so the failure is on the positive step (the purchases vacuity).
    renderAbout(['Unrelated' => 'nothing useful']);

    expect(fn (): mixed => expect('testing')->toLeakNoSecrets(
        secrets: ['super-secret-signing-key'],
        mustRender: ['Sign-count policy'],
    ))->toThrow(AssertionFailedError::class);
});

it('bites when the captured output is empty', function (): void {
    expect(fn (): mixed => expect('no-such-section')->toLeakNoSecrets(
        secrets: ['whatever'],
        mustRender: ['Sign-count policy'],
    ))->toThrow(AssertionFailedError::class);
});

it('throws at call time when mustRender is empty', function (): void {
    expect(fn (): mixed => expect('testing')->toLeakNoSecrets(secrets: ['x'], mustRender: []))
        ->toThrow(InvalidArgumentException::class);
});

// ---------------------------------------------------------------------------
// Static escape hatch.
// ---------------------------------------------------------------------------

it('captures secrets through the static escape hatch', function (): void {
    renderAbout(['Sign-count policy' => 'enabled']);

    Assert::aboutSectionLeaksNoSecrets('testing', ['super-secret-signing-key'], ['Sign-count policy']);

    expect(true)->toBeTrue();
});
