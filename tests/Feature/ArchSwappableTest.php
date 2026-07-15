<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Arch\SwappableModels;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FinalSwappableModel;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SwappableModel;
use RoundlyConsulting\Testing\Tests\Support\ArchPresetsTestCase;

uses(ArchPresetsTestCase::class);

// ---------------------------------------------------------------------------
// Green: a non-final model whose config key defaults to it.
// ---------------------------------------------------------------------------

// Registers a passing preset case (config('arch.record_model') defaults to SwappableModel).
ArchPresets::swappableModelsAreNotFinal([SwappableModel::class => 'arch.record_model']);

it('passes a non-final model whose config default points at it', function (): void {
    SwappableModels::assert([SwappableModel::class => 'arch.record_model']);
});

it('passes the toBeSwappableVia expectation for a correctly-seamed model', function (): void {
    expect(SwappableModel::class)->toBeSwappableVia('arch.record_model');
});

// ---------------------------------------------------------------------------
// Proves-it-bites.
// ---------------------------------------------------------------------------

it('rejects a final swappable model', function (): void {
    expect(fn () => SwappableModels::assert([FinalSwappableModel::class => 'arch.record_model']))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a model the config default does not point at', function (): void {
    expect(fn () => SwappableModels::assert([SwappableModel::class => 'arch.unwired_model']))
        ->toThrow(AssertionFailedError::class);
});

it('rejects an empty map rather than passing vacuously', function (): void {
    expect(fn () => SwappableModels::assert([]))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a mapped model class that does not exist', function (): void {
    /** @var array<class-string, string> $map */
    $map = ['RoundlyConsulting\Testing\Tests\Fixtures\Arch\NoSuchModel' => 'arch.record_model'];

    expect(fn () => SwappableModels::assert($map))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a final model through the toBeSwappableVia expectation', function (): void {
    expect(fn () => expect(FinalSwappableModel::class)->toBeSwappableVia('arch.record_model'))
        ->toThrow(AssertionFailedError::class);
});
