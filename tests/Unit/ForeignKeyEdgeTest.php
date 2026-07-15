<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\Migrations\ForeignKeyEdge;

it('detects a self-referencing edge', function (): void {
    expect((new ForeignKeyEdge('categories', 'categories', 0))->isSelfReferencing())->toBeTrue()
        ->and((new ForeignKeyEdge('posts', 'users', 1))->isSelfReferencing())->toBeFalse();
});
