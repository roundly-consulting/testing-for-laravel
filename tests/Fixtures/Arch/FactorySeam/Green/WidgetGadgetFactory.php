<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Green;

use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Models\Gadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\FactorySeam\Src\Support\GadgetModel;

/** Extends the in-directory base factory rather than Eloquent's directly. */
final class WidgetGadgetFactory extends BaseFactory
{
    protected $model = Gadget::class;

    public function modelName(): string
    {
        return GadgetModel::class();
    }
}
