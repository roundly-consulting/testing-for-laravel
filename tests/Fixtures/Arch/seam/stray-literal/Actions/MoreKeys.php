<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\StrayLiteral\Actions;

/**
 * More stray reads in the other model-key shapes: a bare `model` leaf and a `models`
 * segment. Both must be detected outside the seam.
 */
final class MoreKeys
{
    /**
     * @return array<int, mixed>
     */
    public function all(): array
    {
        return [
            config('widgets.model'),
            config('shop.models.thing'),
        ];
    }
}
