<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Booths;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\KiosksManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Kiosks\Support\OpensBooths;

/**
 * A per-area model that goes through the manager. The OpenBooth in this docblock is prose,
 * not a reference. HasFactory is a vendor trait: outside the package, so never walked.
 */
class Booth extends Model
{
    use HasFactory;
    use OpensBooths;

    public function reopen(): self
    {
        return app(KiosksManager::class)->open($this);
    }
}
