<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assertions\ConfigContract\ConfigContract;

/**
 * The scope of the scrape, and the honesty of the report about that scope.
 *
 * The defect: the scraper walked each srcDir plus a sibling `database/` and nothing else, so
 * a key read from `routes/` scraped as unread and the REVERSE direction reported it as a key
 * "nothing reads", advising the reader to remove it. On `git`, `git.webhooks.middleware` was
 * reported exactly that way while `routes/git-webhooks.php:9` read it — deleting it on that
 * advice would have unregistered the webhook route's middleware.
 *
 * That shape is worse than a false negative. A missed bug costs a bug; a report that presents
 * a scope gap as a dead key *recruits the reader into causing one*, and nothing in the report
 * let them tell the two apart.
 *
 * The fix is two-part, and both parts are pinned below:
 *  1. `routes/` joins `database/` as an auto-scanned sibling — closing the whole real gap
 *     (fleet-wide, every config read outside `src/` is in `routes/`).
 *  2. The finding prints the directories it searched — because the scope is finite whatever
 *     we add to it, so "no reader" is only ever true *of the scanned scope*, and a Blade view
 *     or a host app will always sit outside it.
 */
function routesScanMessage(): string
{
    $base = configContractFixture('routes-scan');

    try {
        ConfigContract::assert($base.'/config/shop.php', $base.'/src', 'shop');
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    return '';
}

/**
 * The bug itself: a key whose only reader is a routes file is a READ key, not a dead one.
 */
it('counts a config read from a sibling routes directory', function (): void {
    expect(routesScanMessage())->not->toContain('shop.webhook_middleware');
});

/**
 * The other half — the guard against the fix becoming an amnesty. Widening the scope adds
 * *reader files*; it must not stop the direction from firing. `shop.escalation_after` is read
 * nowhere, `routes/` included, and stays RED in the very same run that clears the key above.
 * Asserting both against one message is what makes this discriminating: a scrape that quietly
 * matched everything would fail this test, and a scrape still blind to routes/ fails the one
 * above.
 */
it('still reports a key that no scanned file reads', function (): void {
    expect(routesScanMessage())->toContain('shop.escalation_after');
});

/**
 * The report must never again let "unread" be read as "no reader anywhere". It names the
 * directories it actually searched, and says in terms that a key read outside them is not
 * proven dead — so the reader reaches for an extra srcDir rather than the delete key.
 */
it('names the directories it searched and refuses to claim more', function (): void {
    $message = routesScanMessage();
    $base = configContractFixture('routes-scan');

    expect($message)
        ->toContain('no scanned file reads')
        ->toContain('SCOPE — only these directories were searched')
        ->toContain($base.'/src')
        ->toContain($base.'/routes')
        ->toContain('is NOT proven dead');
});
