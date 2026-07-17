<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;

/**
 * The forward and reverse directions are two independent halves of one contract, computed
 * from the same read-set. Aborting on the first one to fail hid the other half entirely:
 * on `kubernetes-api` a forward failure masked 39 unread config keys, which only surfaced
 * once the forward failure was fixed and the suite run again.
 *
 * A package must learn about both halves in one run.
 */
function bothDirectionsMessage(): string
{
    $base = configContractFixture('both-directions');

    try {
        ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop');
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    return '';
}

it('reports the forward finding', function (): void {
    expect(bothDirectionsMessage())->toContain('shop.payments.gateway');
});

/**
 * The defect: these two were computed but never reported, because the forward assertion
 * threw first. This is the dead-key class the reverse direction exists to catch.
 */
it('reports the reverse findings even though the forward direction also failed', function (): void {
    expect(bothDirectionsMessage())
        ->toContain('shop.max_file_size')
        ->toContain('shop.escalation_after');
});

it('keeps both halves legible when both fire', function (): void {
    $message = bothDirectionsMessage();

    expect($message)
        ->toContain('does not ship')
        ->toContain('nothing reads');
});

/**
 * `extraReadPrefixes` counts a literal wherever it appears, which on real packages has meant
 * counting things that are not config keys at all: `kubernetes.io/tls` (an annotation, under
 * the K8s API's own `kubernetes.` namespace), a routes filename `purchases.php`, and a
 * route-name default `alerts.health`. All three failed the forward direction on a key nobody
 * meant to read.
 *
 * None of them is distinguishable from a real key by *shape* — `purchases.php` and
 * `alerts.health` are shaped exactly like config keys — so a filter would be a guess, and a
 * guessing check is the thing this package exists to stop. What the finding CAN do is say
 * which literal it counted, where it found it, and why it counted it. That turns a
 * multi-hour diagnosis into a glance.
 */
it('names the literal, the file, and the reason when extraReadPrefixes invents a read', function (): void {
    $base = configContractFixture('extra-prefix-false-positive');
    $message = '';

    try {
        ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop', [
            'extraReadPrefixes' => ['shop.'],
        ]);
    } catch (AssertionFailedError $e) {
        $message = $e->getMessage();
    }

    expect($message)
        ->toContain('shop.php')
        ->toContain('Provider.php')
        ->toContain('counted because it matches extraReadPrefixes')
        ->toContain('name the keys exactly instead of blanket-prefixing');
});
