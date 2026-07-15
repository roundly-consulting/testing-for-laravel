<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Support\MinimalPackageTestCase;

uses(MinimalPackageTestCase::class);

it('boots with no migration sources and no before-boot config', function (): void {
    // Nothing was loaded through migrationSources(), so the fake package table is absent.
    expect(Schema::hasTable('fake_widgets'))->toBeFalse()
        ->and(config('fake.enabled'))->toBeNull();
});
