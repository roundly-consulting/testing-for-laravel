<?php

declare(strict_types=1);

namespace Fixture\Debug\Leftovers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The debugging helpers Laravel hangs on its own objects — `$query->dd()`,
 * `->ddRawSql()`, `->dumpRawSql()`. As deadly as the global `dd()` and just as easy to leave
 * behind, but reached through `->`, which the ban used to skip wholesale.
 */
final class ChainedDdLeftover
{
    public function inspect(Builder $query, Collection $items): void
    {
        $query->ddRawSql();
        $query->dumpRawSql();
        $items->dd();
    }
}
