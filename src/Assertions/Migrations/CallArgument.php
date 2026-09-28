<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Migrations;

/**
 * One argument of a call as written in migration source: its PHP 8 named-argument label,
 * if any, and its raw expression text — the text a `tableResolvers` key must match.
 *
 * @internal
 */
final readonly class CallArgument
{
    public function __construct(
        public ?string $name,
        public string $text,
    ) {}

    /**
     * The argument bound to a parameter: the named one if present, else the positional one
     * at `$position`. A literal `null` is the parameter's default, so it reads as absent.
     *
     * @param  list<self>  $arguments
     */
    public static function bound(array $arguments, string $name, int $position): ?self
    {
        $positional = [];

        foreach ($arguments as $argument) {
            if ($argument->name === $name) {
                return $argument->isNull() ? null : $argument;
            }

            if ($argument->name === null) {
                $positional[] = $argument;
            }
        }

        $argument = $positional[$position] ?? null;

        return $argument === null || $argument->isNull() ? null : $argument;
    }

    private function isNull(): bool
    {
        return strtolower($this->text) === 'null';
    }
}
