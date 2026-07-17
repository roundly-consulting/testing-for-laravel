<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;

function ccConfig(string $case): string
{
    return configContractFixture($case.'/config/shop.php');
}

function ccSrc(string $case): string
{
    return configContractFixture($case.'/src');
}

// ---------------------------------------------------------------------------
// Green: the contract holds in both directions.
// ---------------------------------------------------------------------------

it('accepts a config where every read is shipped and every leaf is read', function (): void {
    expect(ccConfig('green'))->toSatisfyConfigContract(ccSrc('green'));
});

it('sees DTO array-offset reads through sectionVariables', function (): void {
    expect(ccConfig('section-variables'))->toSatisfyConfigContract(ccSrc('section-variables'), [
        'sectionVariables' => ['Config.php' => ['$rp' => 'shop.rp']],
    ]);
});

it('counts a resolver literal as a read through extraReadPrefixes', function (): void {
    expect(ccConfig('extra-read-prefix'))->toSatisfyConfigContract(ccSrc('extra-read-prefix'), [
        'extraReadPrefixes' => ['shop.'],
    ]);
});

it('scans a sibling database directory for reads', function (): void {
    expect(ccConfig('database-scan'))->toSatisfyConfigContract(ccSrc('database-scan'));
});

it('counts a normal read of a rendered key when nothing is excluded', function (): void {
    expect(ccConfig('exclude-reverse'))->toSatisfyConfigContract(ccSrc('exclude-reverse'));
});

// ---------------------------------------------------------------------------
// Proves-it-bites: each broken fixture goes red.
// ---------------------------------------------------------------------------

it('rejects reading a key the config file does not ship', function (): void {
    expect(fn (): mixed => expect(ccConfig('forward-unshipped'))->toSatisfyConfigContract(ccSrc('forward-unshipped')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a shipped key that nothing reads', function (): void {
    expect(fn (): mixed => expect(ccConfig('reverse-dead-key'))->toSatisfyConfigContract(ccSrc('reverse-dead-key')))
        ->toThrow(AssertionFailedError::class);
});

it('does not count a docblock mention as a read', function (): void {
    // The only mention of shop.documented lives in a docblock; reverse must still bite.
    expect(fn (): mixed => expect(ccConfig('reverse-docblock'))->toSatisfyConfigContract(ccSrc('reverse-docblock')))
        ->toThrow(AssertionFailedError::class);
});

it('flags an interpolated key under the prefix', function (): void {
    expect(fn (): mixed => expect(ccConfig('interpolated'))->toSatisfyConfigContract(ccSrc('interpolated')))
        ->toThrow(AssertionFailedError::class);
});

it('falsely reports offset reads unread without sectionVariables', function (): void {
    expect(fn (): mixed => expect(ccConfig('section-variables'))->toSatisfyConfigContract(ccSrc('section-variables')))
        ->toThrow(AssertionFailedError::class);
});

it('reports the resolver-read key unread without extraReadPrefixes', function (): void {
    expect(fn (): mixed => expect(ccConfig('extra-read-prefix'))->toSatisfyConfigContract(ccSrc('extra-read-prefix')))
        ->toThrow(AssertionFailedError::class);
});

it('does not count a render in an excluded file as a read', function (): void {
    expect(fn (): mixed => expect(ccConfig('exclude-reverse'))->toSatisfyConfigContract(ccSrc('exclude-reverse'), [
        'excludeFromReverse' => ['Provider.php'],
    ]))->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Escape hatches — and their rot guards.
// ---------------------------------------------------------------------------

it('silences a genuinely dead key through allowUnread', function (): void {
    expect(ccConfig('reverse-dead-key'))->toSatisfyConfigContract(ccSrc('reverse-dead-key'), [
        'allowUnread' => ['shop.escalation'],
    ]);
});

it('rejects an allowUnread entry for a key that is actually read', function (): void {
    expect(fn (): mixed => expect(ccConfig('green'))->toSatisfyConfigContract(ccSrc('green'), [
        'allowUnread' => ['shop.payments.gateway'],
    ]))->toThrow(AssertionFailedError::class);
});

it('rejects an allowUnread entry for a key that is not shipped', function (): void {
    expect(fn (): mixed => expect(ccConfig('green'))->toSatisfyConfigContract(ccSrc('green'), [
        'allowUnread' => ['shop.nonexistent'],
    ]))->toThrow(AssertionFailedError::class);
});

it('silences a read-but-unshipped key through allowUnshipped', function (): void {
    expect(ccConfig('forward-unshipped'))->toSatisfyConfigContract(ccSrc('forward-unshipped'), [
        'reverse' => false,
        'allowUnshipped' => ['shop.payments.gateway'],
    ]);
});

it('rejects a stale allowUnshipped entry', function (): void {
    expect(fn (): mixed => expect(ccConfig('green'))->toSatisfyConfigContract(ccSrc('green'), [
        'reverse' => false,
        'allowUnshipped' => ['shop.not-read'],
    ]))->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// Forward-only mode for whole apps.
// ---------------------------------------------------------------------------

it('runs forward-only when reverse is disabled', function (): void {
    // reverse-dead-key ships an unread key, which forward-only must ignore.
    expect(ccConfig('reverse-dead-key'))->toSatisfyConfigContract(ccSrc('reverse-dead-key'), ['reverse' => false]);
});

/**
 * #6, end to end: a section indexed two levels deep.
 *
 * `sectionVariables` handled exactly one offset level, so every leaf under a named profile
 * scraped as unread — a report identical to media #27's genuinely-dead `max_file_size`. This
 * is the `cosmos-foundation` shape (`rate_limiters.*.{enabled,per_minute}`), which had to be
 * unrolled into literal `config()` reads to get a green contract.
 */
it('satisfies the contract through nested section-variable offsets', function (): void {
    expect(ccConfig('nested-section-variables'))->toSatisfyConfigContract(ccSrc('nested-section-variables'), [
        'sectionVariables' => ['Limiters.php' => ['$rl' => 'shop.rate_limiters']],
    ]);
});
