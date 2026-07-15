<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\FakePackageTestCase;
use RoundlyConsulting\Testing\Tests\Support\FakeWidget;

uses(FakePackageTestCase::class);

it('runs a package provider migrations loaded by class', function (): void {
    expect(Schema::hasTable('fake_widgets'))->toBeTrue();
});

it('turns sqlite foreign key constraints on', function (): void {
    expect(config('database.connections.testing.foreign_key_constraints'))->toBeTrue();
});

it('applies configBeforeBoot values before the providers boot', function (): void {
    expect(config('fake.enabled'))->toBeTrue();
});

it('applies a configured model swap before the providers boot', function (): void {
    expect(config('fake.widget_model'))->toBe(FakeWidget::class);
});
