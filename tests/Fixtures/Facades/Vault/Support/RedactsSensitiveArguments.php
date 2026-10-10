<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Support;

use RuntimeException;
use SensitiveParameterValue;

/**
 * A copy of package-toolkit-for-laravel's `Concerns\RedactsSensitiveArguments` (1.3): the
 * selective redaction the expectation exists to prove. The toolkit's file is deliberately not
 * strict (it keeps Laravel's call-site coercion); that has no bearing on what lands in a frame,
 * so this copy follows this package's strict-types rule.
 */
trait RedactsSensitiveArguments
{
    /**
     * @var array<string, array<string, SensitiveArguments|false>>
     */
    private static array $sensitiveArguments = [];

    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        $forward = $args;
        $sensitive = self::$sensitiveArguments[static::class][$method]
            ??= SensitiveArguments::of(static::getFacadeAccessor(), $method) ?? false;

        if ($sensitive !== false) {
            foreach ($sensitive->positions as $position) {
                if (array_key_exists($position, $args)) {
                    $args[$position] = new SensitiveParameterValue($args[$position]);
                }
            }

            if ($sensitive->variadic !== null || ! array_is_list($args)) {
                $args = $sensitive->redactNamedAndVariadic($args);
            }
        }

        $instance = static::getFacadeRoot();

        if (! $instance) {
            throw new RuntimeException('A facade root has not been set.');
        }

        return $instance->$method(...$forward);
    }
}
