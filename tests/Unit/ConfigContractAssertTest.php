<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;

it('passes the config contract through the static escape hatch', function (): void {
    Assert::configContract(
        configContractFixture('green/config/shop.php'),
        configContractFixture('green/src'),
    );

    expect(true)->toBeTrue();
});

it('bites through the static escape hatch', function (): void {
    expect(fn (): mixed => Assert::configContract(
        configContractFixture('forward-unshipped/config/shop.php'),
        configContractFixture('forward-unshipped/src'),
    ))->toThrow(AssertionFailedError::class);
});

it('accepts a list of source directories', function (): void {
    Assert::configContract(
        configContractFixture('green/config/shop.php'),
        [configContractFixture('green/src')],
    );

    expect(true)->toBeTrue();
});

it('fails when a source directory does not exist', function (): void {
    expect(fn (): mixed => Assert::configContract(
        configContractFixture('green/config/shop.php'),
        configContractFixture('green/does-not-exist'),
    ))->toThrow(AssertionFailedError::class);
});
