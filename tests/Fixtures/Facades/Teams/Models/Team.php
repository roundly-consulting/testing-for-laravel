<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\TeamsManager;

/**
 * Its convenience method goes through the manager, never an action — so a fake sees it.
 * The RenameTeam in this docblock is prose, not a reference.
 */
class Team extends Model
{
    protected $guarded = [];

    public function rename(string $name): self
    {
        return app(TeamsManager::class)->for($this)->rename($name);
    }
}
