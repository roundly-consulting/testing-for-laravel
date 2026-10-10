<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support;

use ReflectionMethod;
use SensitiveParameter;
use SensitiveParameterValue;

/**
 * A copy of package-toolkit-for-laravel's `Support\SensitiveArguments` (1.3), the lookup behind
 * {@see RedactsSensitiveArguments}. This package cannot require the toolkit, so the green path
 * runs against this copy.
 */
final readonly class SensitiveArguments
{
    /**
     * @param  list<int>  $positions
     * @param  array<string, true>  $names
     * @param  array<string, true>  $declared
     */
    private function __construct(
        public array $positions,
        public array $names,
        public array $declared,
        public ?int $variadic,
        public bool $unknownNamesAreSensitive,
    ) {}

    public static function of(string $class, string $method): ?self
    {
        if (! method_exists($class, $method)) {
            return null;
        }

        $positions = [];
        $names = [];
        $declared = [];
        $variadic = null;
        $unknownNamesAreSensitive = true;

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $declared[$parameter->getName()] = true;
            $sensitive = $parameter->getAttributes(SensitiveParameter::class) !== [];

            if ($sensitive) {
                $positions[] = $parameter->getPosition();
                $names[$parameter->getName()] = true;
            }

            if ($parameter->isVariadic()) {
                $variadic = $sensitive ? $parameter->getPosition() : null;
                $unknownNamesAreSensitive = $sensitive;
            }
        }

        return $positions === [] ? null : new self($positions, $names, $declared, $variadic, $unknownNamesAreSensitive);
    }

    /**
     * @param  array<array-key, mixed>  $args
     * @return array<array-key, mixed>
     */
    public function redactNamedAndVariadic(#[SensitiveParameter] array $args): array
    {
        foreach ($args as $key => $value) {
            if ($value instanceof SensitiveParameterValue) {
                continue;
            }

            $sensitive = is_int($key)
                ? $this->variadic !== null && $key >= $this->variadic
                : isset($this->names[$key]) || (! isset($this->declared[$key]) && $this->unknownNamesAreSensitive);

            if ($sensitive) {
                $args[$key] = new SensitiveParameterValue($value);
            }
        }

        return $args;
    }
}
