<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Migrations;

use Illuminate\Database\Eloquent\Model;

/**
 * A model whose table is NOT its conventional name — `foreignIdFor(Imprint::class)` must
 * resolve `imprints_catalogue` from the model, never guess `imprints` from the class name.
 */
final class Imprint extends Model
{
    protected $table = 'imprints_catalogue';
}
