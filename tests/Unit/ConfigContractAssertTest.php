<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;

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

/**
 * #5: the interpolation error must not advertise an escape that does not exist.
 *
 * It used to end "Make the key a literal string, or add it to allowUnshipped/allowUnread" —
 * but this check runs before the forward and reverse checks and consults neither list, so
 * taking that advice produced the identical error a second time. The message must name only
 * remedies that work.
 */
it('does not offer an allow-list escape for an interpolated key', function (): void {
    $base = dirname(__DIR__).'/Fixtures/config-contract/interpolated';

    $message = '';

    try {
        ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop');
    } catch (AssertionFailedError $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('There is no allow-list for this')
        ->and($message)->not->toContain('add it to allowUnshipped/allowUnread');
});

/**
 * And the advice it *does* give must be the truth: the allow-lists genuinely cannot silence
 * this, so passing them changes nothing.
 */
it('still flags an interpolated key when both allow-lists name it', function (): void {
    $base = dirname(__DIR__).'/Fixtures/config-contract/interpolated';

    expect(fn () => ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop', [
        'allowUnshipped' => ['shop.drivers'],
        'allowUnread' => ['shop.drivers'],
    ]))->toThrow(AssertionFailedError::class, 'There is no allow-list for this');
});
