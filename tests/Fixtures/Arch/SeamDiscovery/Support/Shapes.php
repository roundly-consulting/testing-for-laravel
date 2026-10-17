<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Contracts\Shape;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Cog;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Gadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Sprocket;

/** Every near-miss of the seam shape next to the real thing. */
final class Shapes
{
    public static function gadget(): string
    {
        return (string) config('arch.gadget_model', Gadget::class);
    }

    /** Not static: no seam. */
    public function instanceCog(): string
    {
        return (string) config('arch.cog_model', Cog::class);
    }

    /** Takes a parameter: no seam. */
    public static function withParameter(string $fallback): string
    {
        return (string) config('arch.cog_model', Cog::class) ?: $fallback;
    }

    /** No key beside the model: no seam. */
    public static function noKey(): string
    {
        return Cog::class;
    }

    /** Not a string return: no seam. */
    public static function notString(): ?string
    {
        return (string) config('arch.cog_model', Cog::class);
    }

    /** An interface is not a model. */
    public static function contract(): string
    {
        return (string) config('arch.shape_model', Shape::class);
    }

    /** Eloquent's own base is not a swappable model. */
    public static function base(): string
    {
        return (string) config('arch.base_model', Model::class);
    }

    /** A read-then-is_a check names its model three times: still one swappable model. */
    public static function sprocket(): string
    {
        $model = config('arch.sprocket_model', Sprocket::class);

        return is_string($model) && is_a($model, Sprocket::class, true) ? $model : Sprocket::class;
    }
}
