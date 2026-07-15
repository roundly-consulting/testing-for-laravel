<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch;

use Illuminate\Database\Eloquent\Model;

/**
 * The seven-times fatal: a config-swappable model shipped `final`, so a host swap
 * cannot extend it. The `swappableModelsAreNotFinal` preset must go red on this.
 */
final class FinalSwappableModel extends Model
{
    protected $guarded = [];
}
