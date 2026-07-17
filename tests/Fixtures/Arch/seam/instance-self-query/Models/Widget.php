<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\InstanceSelfQuery\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The false positive that `refresh-tokens`, `jwt` and `connections` all hit.
 *
 * `prunable()` is an INSTANCE method — the shape `model:prune` calls, on whatever class the
 * host configured. `self::` is a *forwarding* call, so late static binding is preserved and
 * `self::query()` here builds a query for `$this`'s runtime class, which is already the
 * configured one. Verified, not assumed: `(new Sub)->viaSelf()` returns `Sub`.
 *
 * Routing this "through the seam" would introduce a bug rather than fix one — it would prune
 * a host's un-configured subclass as the packaged base class. The preset is an `it()` case
 * with no `->ignoring()` escape, so flagging it is unappealable.
 */
class Widget extends Model
{
    protected $guarded = [];

    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }
}
