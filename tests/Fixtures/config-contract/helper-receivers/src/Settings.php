<?php

declare(strict_types=1);

namespace Shop;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\App;

// The repository reached through an expression rather than a typed variable — Laravel 11+'s
// typed accessors (`config()->string()`) and the container's own spellings.
final class Settings
{
    public function __construct(private Application $app) {}

    public function read(): void
    {
        config()->string('shop.a');
        \config()->get('shop.b');
        app('config')->get('shop.c');
        app(Repository::class)->integer('shop.d');
        $this->app['config']->get('shop.e');
        app()->make('config')->boolean('shop.f');
        resolve('config')->get('shop.g');
        App::make('config')->get('shop.h');
        Container::getInstance()->make(Repository::class)->get('shop.i');
        config()->get(['shop.j' => 'fallback']);
    }
}
