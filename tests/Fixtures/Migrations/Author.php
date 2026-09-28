<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Migrations;

use Illuminate\Database\Eloquent\Model;

/**
 * A model a migration names through `foreignIdFor(Author::class)` — conventional table.
 */
final class Author extends Model {}
