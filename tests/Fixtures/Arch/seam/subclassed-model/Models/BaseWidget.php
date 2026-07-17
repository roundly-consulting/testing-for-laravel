<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\SubclassedModel\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An intermediate package base class. A model reaching Model through this is still a
 * model, so the ban must follow the whole chain.
 */
abstract class BaseWidget extends Model
{
    protected $guarded = [];
}
