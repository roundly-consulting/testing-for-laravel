<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Manager;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Actions\CreateTeam;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Models\Team;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Teams\Support\Pruner;

/**
 * The green manager. Vendor API it inherits (Manager::driver(), Macroable::macro(), the
 * getDefaultDriver() it implements) is not the package's to document; bootstrap() is
 *
 * @internal; Pruner is held in a property and reached only through `$via`.
 */
class TeamsManager extends Manager
{
    use Macroable;

    public function __construct(Container $container, protected Pruner $pruner)
    {
        parent::__construct($container);
    }

    public function getDefaultDriver(): string
    {
        return 'database';
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function create(string $name, array $options = []): Team
    {
        return $this->container->make(CreateTeam::class)->execute($name, $options);
    }

    public function for(Team $team): TeamHandle
    {
        return new TeamHandle($this->container, $team);
    }

    /**
     * @param  array<string, int>  $map
     * @param  array<string, mixed>  $defaults
     */
    public function sync(array $map, Closure $resolver, array $defaults = [], string ...$tags): int
    {
        return count($map) + count($defaults) + count($tags);
    }

    public function fresh(): static
    {
        return $this;
    }

    public function prune(): int
    {
        return $this->pruner->run();
    }

    /**
     * @internal wiring for the service provider only.
     */
    public function bootstrap(): void {}
}
