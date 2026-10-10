<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Shapes;

use Acme;
use Acme\Billing\Invoice as Bill;
use Acme\{Shipping\Zone, Tax\Rate};
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Shapes\Support\Helper;
use function Acme\Helpers\money;
use const Acme\Limits\MAX;

/**
 * Every way code names a namespace, each pointing at `Acme` — a vendor that is not installed —
 * or at the host's `App`. Nothing here is ever loaded. Excluded from Pint (pint.json), which
 * would rewrite the very shapes under test.
 */
#[\Acme\Attributes\Audited]
final class Everything extends \Acme\Base\Model implements \App\Contracts\Tenant
{
    public function run(Bill $bill, Zone $zone, Rate $rate, Helper $helper): mixed
    {
        try {
            return money($bill, $zone, $rate, MAX, Acme\Report\Pdf::render(), Bill\Line::class, $helper);
        } catch (\Acme\Errors\Failed $e) {
            return \App\Models\User::query();
        }
    }
}
