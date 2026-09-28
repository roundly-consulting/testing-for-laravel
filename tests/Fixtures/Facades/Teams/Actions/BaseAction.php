<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions;

/**
 * Abstract: never host-facing on its own.
 */
abstract class BaseAction
{
    abstract public function name(): string;
}
