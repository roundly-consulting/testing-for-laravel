<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\BootedHooks\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The shape 35 fleet `booted()` hooks share: static calls to Model's OWN statics (event and
 * scope registration) and to the class's own helpers. None is forwarded to a query, so none
 * bypasses the seam — the ban must stay green here.
 */
class Tag extends Model
{
    public const string DEFAULT = 'general';

    protected static string $separator = '-';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $tag): void {
            $tag->slug ??= static::slugFor((string) $tag->name);
        });

        static::addGlobalScope('visible', fn (Builder $query): Builder => $query->where('hidden', false));
        self::saving(fn (self $tag): bool => $tag->name !== static::DEFAULT.static::$separator);
    }

    private static function slugFor(string $name): string
    {
        return strtolower($name);
    }
}
