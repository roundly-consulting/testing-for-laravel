<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\Seam\NewSelf\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * `new self` always builds the packaged class — even from an instance method, where `$this` is
 * the host's subclass: `(new HostTag)->duplicate()` returns a `Tag`.
 */
class Tag extends Model
{
    protected $guarded = [];

    public function duplicate(): self
    {
        return new self($this->getAttributes());
    }
}
