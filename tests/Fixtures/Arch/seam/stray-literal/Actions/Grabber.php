<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StrayLiteral\Actions;

/**
 * A competing resolution path: this action reads the swap literal itself, outside the
 * Support seam. It will drift from the seam. The seam preset must go red on this.
 */
final class Grabber
{
    public function resolve(): string
    {
        $model = config('arch.record_model');

        return is_string($model) ? $model : '';
    }
}
