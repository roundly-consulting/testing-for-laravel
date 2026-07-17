<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;

/**
 * A driver-keyed config section — Laravel's own `database.connections.<name>` shape — is
 * read by interpolating the driver name. `git` reads 52 leaves this way and `certificates`,
 * `cosmos-foundation` and `geolocation` all carry the shape.
 *
 * The contract has to check these, because the alternatives on offer were both dishonest:
 * `allowUnread` asserts a falsehood about live keys and blinds the very reverse check that
 * caught media #27, and unrolling every driver by hand does not scale.
 *
 * The bar: the wildcard must still *prove* each leaf is read. It is not licence to assume
 * everything under the section is fine.
 */
function driverKeyed(array $options = []): void
{
    $base = configContractFixture('driver-keyed');

    ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop', $options);
}

it('proves an interpolated driver leaf is read', function (): void {
    driverKeyed([
        'sectionVariables' => ['RateLimits.php' => ['$limits' => 'shop.providers.*.rate_limits']],
    ]);

    expect(true)->toBeTrue();
});

/**
 * The load-bearing half. A leaf under the *same* driver-keyed shape that nothing reads must
 * still go red — otherwise the wildcard is `allowUnread` wearing a hat.
 */
it('still bites on a dead leaf under the same driver-keyed shape', function (): void {
    $base = configContractFixture('driver-keyed-dead-leaf');

    expect(fn () => ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop', [
        'sectionVariables' => ['RateLimits.php' => ['$limits' => 'shop.providers.*.rate_limits']],
    ]))->toThrow(AssertionFailedError::class, 'shop.providers.github.timeout');
});

/**
 * A wildcard is a claim about which section a variable holds, and the forward direction
 * checks that claim: a base path matching nothing shipped is rot, and must be reported
 * rather than silently proving leaves that do not exist.
 */
it('fails forward when a wildcard base path matches nothing shipped', function (): void {
    $base = configContractFixture('driver-keyed');

    expect(fn () => ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop', [
        'sectionVariables' => ['RateLimits.php' => ['$limits' => 'shop.provider.*.rate_limits']],
    ]))->toThrow(AssertionFailedError::class, 'shop.provider.*.rate_limits');
});

/**
 * A read that stops at the driver hole pulls the whole section into a local. That is a
 * wholesale read and proves no leaf — the same verdict the literal `config('shop.providers')`
 * has always got. Unmapped, its leaves therefore read as dead.
 *
 * The reverse failure has to say why, or the degrade would be a downgrade: this shape used to
 * produce a precise "interpolated key cannot be checked", and trading that for a bare list of
 * leaves nothing reads would cost the reader the diagnosis.
 */
it('explains a reverse failure caused by a wholesale dynamic section read', function (): void {
    $base = configContractFixture('wholesale-dynamic');
    $message = '';

    try {
        ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop');
    } catch (AssertionFailedError $e) {
        $message = $e->getMessage();
    }

    expect($message)
        ->toContain('shop.providers.github.timeout')
        ->toContain('read wholesale through a dynamic key')
        ->toContain('BaseProvider.php')
        ->toContain('sectionVariables');
});

/**
 * And the mapping is the way out — the same shape, declared, proves both leaves. The `*` in
 * the base path carries the driver; the literal offsets carry the proof.
 */
it('proves the leaves of a wholesale dynamic section once it is mapped', function (): void {
    $base = configContractFixture('wholesale-dynamic');

    ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop', [
        'sectionVariables' => ['BaseProvider.php' => ['$http' => 'shop.providers.*']],
    ]);

    expect(true)->toBeTrue();
});
