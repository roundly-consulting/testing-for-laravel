<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\UnresolvedKey\Support;

/**
 * Reads the swap key, but takes the default as an argument — so no seam method names the
 * default beside the key, and which model it swaps cannot be read from the code.
 */
final class GadgetModel
{
    public static function resolve(string $default): string
    {
        $model = config('arch.gadget_model', $default);

        return is_string($model) ? $model : $default;
    }
}
