<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\Internal;

/**
 * A building block only other actions call — not host-facing, so the facade need not
 * reach it.
 *
 * @internal
 */
final class RecordAudit
{
    public function execute(string $event): void {}
}
