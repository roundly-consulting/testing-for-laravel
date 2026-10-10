<?php

declare(strict_types=1);

namespace Shop;

use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

// `string`, `float` and `list` are everyday names. None of these is a toolkit reader: a
// request's typed getters, a Stringable, a same-named static on another class, a chain off
// a non-toolkit factory and the package's own list() method. (The `list()` destructure is
// pinned in TokenScraperTest: Pint rewrites it to `[...] =` in a committed file.)
final class Lookalikes
{
    /** @return list<mixed> */
    public function handle(Request $request): array
    {
        return [
            $request->string('shop.title'),
            $request->float('shop.ratio'),
            Str::of($request->path())->string('shop.slug'),
            Settings::list('shop.hosts', []),
            Cache::using(Store::class)->string('shop.name', ''),
            $this->list('shop.channels', []),
        ];
    }

    /** @return list<mixed> */
    public function list(string $key = 'shop.mirrors', array $default = []): array
    {
        return $default;
    }
}
