<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions\Facades;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use SensitiveParameter;
use Throwable;

/**
 * A facade must keep the arguments its root marks `#[SensitiveParameter]` out of **every**
 * stack frame of a call made through it — and only those.
 *
 * ## The leak this pins
 *
 * PHP redacts a sensitive argument only in the frame of the function that declares the
 * attribute. A call through a stock facade passes `Facade::__callStatic($method, $args)` first,
 * and that frame carries every argument raw: `Crypto::constantTimeEquals($known, $input)` puts
 * `$known` where an error tracker that collects frame arguments, `print_r($e)` or a trace logger
 * finds it. `getTraceAsString()` prints the frame as `__callStatic('…', Array)`, so a test on the
 * trace **string** passes on the leaking facade too. This reads the frame arguments.
 *
 * ## What is checked
 *
 * 1. **A pinned count.** `$methods` must equal the number of public methods of the accessor type
 *    (the class or interface `getFacadeAccessor()` names) with at least one
 *    `#[SensitiveParameter]` — static ones too, since the facade reaches them. The pin fails
 *    when an attribute is lost or gained, and `methods: 0` is a construction error: it would
 *    pass over a root that marks nothing.
 * 2. **Interface drift.** A facade sees only the accessor type's attributes. When the booted
 *    application binds another class under the accessor, the two must mark the same
 *    parameters: one marked only on the implementation shows raw in the facade frame, one
 *    marked only on the interface shows raw in the implementation's own frame (and through
 *    DI). A public method only the implementation declares must mark nothing. An active
 *    `fake()` is looked through to the implementation it extends.
 * 3. **A real trace.** With `zend.exception_ignore_args` off for the check, a
 *    {@see RedactionProbe} root that throws on any call is swapped in, and every sensitive
 *    method is called through the facade — positionally and with named arguments, two values
 *    for a variadic — with a unique probe value per argument. The facade's own frame
 *    (`__callStatic`, or a real static it declares) must be in the trace with its arguments;
 *    no frame may hold a sensitive value raw; and every harmless value must still show in the
 *    facade frame. Every other public root method that takes arguments is called once too, and
 *    must keep all of them visible: a facade that hides every argument passes the leak check
 *    and fails this one. Strings and arrays are searched; objects are not opened.
 *
 * The facade root, the container entry for the accessor and the ini setting are restored
 * afterwards, as {@see FacadeFake} does, so this composes with the other facade expectations in
 * any order.
 */
final class FacadeRedaction
{
    private const string IGNORE_ARGS = 'zend.exception_ignore_args';

    public static function assert(string $facade, int $methods): void
    {
        if ($methods < 1) {
            throw new InvalidArgumentException(
                "toRedactSensitiveArguments() needs methods: N of at least 1 (got {$methods}): the number of root "
                .'methods that take a #[SensitiveParameter] argument. A pin of 0 passes over a root that marks '
                .'nothing. A facade whose root takes no secret has nothing to redact; do not call it there.',
            );
        }

        $subject = FacadeSubject::resolve($facade);
        $root = new ReflectionClass($subject->accessor);
        $sensitive = self::sensitiveMethods($root);
        $problems = [];

        if (count($sensitive) !== $methods) {
            $problems[] = self::countMismatch($subject, $methods, $sensitive);
        }

        $restore = self::snapshot($subject);

        try {
            array_push($problems, ...self::drift($subject, $root));

            $subject->facade::swap(new RedactionProbe);

            array_push($problems, ...self::probeEveryMethod($subject, $root, $sensitive));
        } finally {
            $restore();
        }

        // A variadic takes two probe values, and both report the same argument.
        $problems = array_values(array_unique($problems));

        Assert::assertSame(
            [],
            $problems,
            "{$subject->facade} does not pass toRedactSensitiveArguments(methods: {$methods}) over its root "
            ."{$subject->accessor}:\n  - ".implode("\n  - ", $problems),
        );
    }

    /**
     * The accessor type's public methods with at least one `#[SensitiveParameter]`, keyed by
     * lower-case name.
     *
     * @param  ReflectionClass<object>  $root
     * @return array<string, ReflectionMethod>
     */
    private static function sensitiveMethods(ReflectionClass $root): array
    {
        $sensitive = [];

        foreach ($root->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! str_starts_with($method->name, '__') && self::marksAny($method)) {
                $sensitive[strtolower($method->name)] = $method;
            }
        }

        return $sensitive;
    }

    private static function marksAny(ReflectionMethod $method): bool
    {
        foreach ($method->getParameters() as $parameter) {
            if (self::isSensitive($parameter)) {
                return true;
            }
        }

        return false;
    }

    private static function isSensitive(ReflectionParameter $parameter): bool
    {
        return $parameter->getAttributes(SensitiveParameter::class) !== [];
    }

    /**
     * @param  array<string, ReflectionMethod>  $sensitive
     */
    private static function countMismatch(FacadeSubject $subject, int $methods, array $sensitive): string
    {
        if ($sensitive === []) {
            return "methods: {$methods}, but no public method of {$subject->accessor} marks a parameter "
                .'#[SensitiveParameter], so there is nothing to redact. Mark the secret parameters on the root '
                .'first, or drop the expectation if the facade takes no secret.';
        }

        $names = array_map(static fn (ReflectionMethod $method): string => $method->name.'()', array_values($sensitive));

        return "methods: {$methods}, but ".count($sensitive)." public method(s) of {$subject->accessor} take a "
            .'#[SensitiveParameter] argument: '.implode(', ', $names).'. The pin exists to catch an attribute '
            .'that was lost or added, so check that list before you change N.';
    }

    /**
     * What the facade and the container hold for the accessor right now, as a closure that puts
     * it back.
     *
     * @return Closure(): void
     */
    private static function snapshot(FacadeSubject $subject): Closure
    {
        $facade = $subject->facade;
        $accessor = $subject->accessor;
        $app = Facade::getFacadeApplication();

        if ($app instanceof Container) {
            $previous = $app->resolved($accessor) && $app->isShared($accessor) ? $app->make($accessor) : null;

            return static function () use ($facade, $accessor, $app, $previous): void {
                $facade::clearResolvedInstance();

                if ($previous !== null) {
                    $app->instance($accessor, $previous);
                } else {
                    $app->forgetInstance($accessor);
                }
            };
        }

        // No application: the facade holds only what was swapped in, and reading that resolves nothing.
        $previous = $facade::getFacadeRoot();

        return static function () use ($facade, $previous): void {
            $facade::clearResolvedInstance();

            if ($previous !== null) {
                $facade::swap($previous);
            }
        };
    }

    /**
     * @param  ReflectionClass<object>  $root
     * @return list<string>
     */
    private static function drift(FacadeSubject $subject, ReflectionClass $root): array
    {
        $problems = [];
        $implementation = self::implementation($subject, $problems);

        if ($implementation === null || strtolower($implementation) === strtolower($subject->accessor)) {
            return $problems;
        }

        foreach ((new ReflectionClass($implementation))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->name, '__')) {
                continue;
            }

            $declared = $root->hasMethod($method->name) ? $root->getMethod($method->name)->getParameters() : null;

            foreach ($method->getParameters() as $parameter) {
                $onImplementation = self::isSensitive($parameter);
                $onRoot = isset($declared[$parameter->getPosition()]) && self::isSensitive($declared[$parameter->getPosition()]);
                $where = "{$method->name}(), ".self::label($parameter);

                if ($onImplementation && $declared === null) {
                    $problems[] = "{$where}: {$implementation} marks it #[SensitiveParameter], but {$subject->accessor} "
                        ."does not declare {$method->name}(), so the facade cannot see the attribute. Declare the "
                        .'method on the accessor type with the attribute.';
                } elseif ($onImplementation && ! $onRoot) {
                    $problems[] = "{$where}: {$implementation} marks it #[SensitiveParameter] and {$subject->accessor} "
                        .'does not. The facade reads the accessor type, so the value shows raw in the facade frame. '
                        ."Mark it on {$subject->accessor} too.";
                } elseif ($onRoot && ! $onImplementation) {
                    $problems[] = "{$where}: {$subject->accessor} marks it #[SensitiveParameter] and {$implementation} "
                        ."does not, so the implementation's own frame shows it raw, through the facade and through "
                        ."dependency injection alike. Mark it on {$implementation} too.";
                }
            }
        }

        return $problems;
    }

    /**
     * The class the application binds under the accessor, looked through the facade's fake to the
     * implementation it extends. Null when there is nothing to compare the accessor type with.
     *
     * @param  list<string>  $problems
     * @return class-string|null
     */
    private static function implementation(FacadeSubject $subject, array &$problems): ?string
    {
        $app = Facade::getFacadeApplication();
        $interface = interface_exists($subject->accessor);

        if (! $app instanceof Container) {
            if ($interface) {
                $problems[] = "{$subject->accessor} is an interface and no application is booted, so the class bound "
                    .'under it was not compared with it. Bind this test to your PackageTestCase-based TestCase '
                    .'(`uses(TestCase::class)`).';
            }

            return null;
        }

        if (! $app->bound($subject->accessor)) {
            if ($interface) {
                $problems[] = "{$subject->accessor} is not bound in the container, so {$subject->facade} has no root to "
                    .'forward to. Bind it in the service provider and register that provider in the TestCase.';
            }

            return null;
        }

        try {
            $instance = $app->make($subject->accessor);
        } catch (Throwable $e) {
            $problems[] = "resolving app({$subject->accessor}) threw ".$e::class.": {$e->getMessage()}";

            return null;
        }

        if (! is_object($instance)) {
            $problems[] = "app({$subject->accessor}) resolves to ".get_debug_type($instance).', not an object, so '
                ."{$subject->facade} has no root to forward to.";

            return null;
        }

        $class = $instance::class;

        while ($subject->isFake($class)) {
            $class = get_parent_class($class);

            if ($class === false) {
                $problems[] = "app({$subject->accessor}) resolves to the fake ".$instance::class.' and no implementation '
                    .'stands behind it, so the implementation was not compared with the accessor type. Run '
                    .'toRedactSensitiveArguments() before faking.';

                return null;
            }
        }

        return $class;
    }

    /**
     * @param  ReflectionClass<object>  $root
     * @param  array<string, ReflectionMethod>  $sensitive
     * @return list<string>
     */
    private static function probeEveryMethod(FacadeSubject $subject, ReflectionClass $root, array $sensitive): array
    {
        $problems = [];
        $ignoreArgs = ini_set(self::IGNORE_ARGS, '0');

        try {
            foreach ($root->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (str_starts_with($method->name, '__') || $method->getNumberOfParameters() === 0) {
                    continue;
                }

                $secret = isset($sensitive[strtolower($method->name)]);
                $declaredBy = self::facadeStatic($subject->facade, $method->name);

                if ($declaredBy === Facade::class) {
                    if ($secret) {
                        $problems[] = "{$method->name}(): ".Facade::class." declares a public static {$method->name}(), so "
                            ."{$subject->shortFacade()}::{$method->name}() never reaches the root method and the probe "
                            .'does not call it. Rename the root method.';
                    }

                    continue;
                }

                if (! $secret) {
                    // A real static the facade declares for a harmless method may do real work; skip it.
                    if ($declaredBy === null) {
                        array_push($problems, ...self::probe($subject, $method, named: false));
                    }

                    continue;
                }

                array_push(
                    $problems,
                    ...self::probe($subject, $method, named: false),
                    ...self::probe($subject, $method, named: true),
                );
            }
        } finally {
            if ($ignoreArgs !== false) {
                ini_set(self::IGNORE_ARGS, $ignoreArgs);
            }
        }

        return $problems;
    }

    /**
     * The class declaring a public static `$method` on the facade, or null when a call by that
     * name goes to `__callStatic`.
     */
    private static function facadeStatic(string $facade, string $method): ?string
    {
        if (! method_exists($facade, $method)) {
            return null;
        }

        $reflection = new ReflectionMethod($facade, $method);

        return $reflection->isPublic() && $reflection->isStatic() ? $reflection->getDeclaringClass()->getName() : null;
    }

    /**
     * Call one root method through the facade with a fresh probe value per argument, then read
     * the trace. The values are built here, never passed in: no frame of this class may hold them
     * raw either.
     *
     * @return list<string>
     */
    private static function probe(FacadeSubject $subject, ReflectionMethod $method, bool $named): array
    {
        $arguments = [];
        $secrets = [];
        $harmless = [];

        foreach ($method->getParameters() as $parameter) {
            $keys = match (true) {
                ! $parameter->isVariadic() => [$named ? $parameter->getName() : count($arguments)],
                $named => ['extra'.bin2hex(random_bytes(4))],
                default => [count($arguments), count($arguments) + 1],
            };

            foreach ($keys as $key) {
                $value = 'redaction-probe-'.bin2hex(random_bytes(8));
                $arguments[$key] = $value;

                if (self::isSensitive($parameter)) {
                    $secrets[$value] = self::label($parameter);
                } else {
                    $harmless[$value] = self::label($parameter);
                }
            }
        }

        $facade = $subject->facade;
        $name = $method->name;
        $call = $subject->shortFacade()."::{$name}(".($named ? 'named arguments' : '').')';

        try {
            self::call($facade, $name, $arguments);
        } catch (RedactionProbeReached $reached) {
            return self::inspect($subject, $name, $reached->getTrace(), $call, $secrets, $harmless);
        } catch (Throwable $e) {
            return ["{$call}: threw ".$e::class." before it reached the root: {$e->getMessage()}"];
        }

        return ["{$call}: returned without reaching the root, so there was no trace to read. A facade forwards "
            .'every call to its root.'];
    }

    /**
     * The one frame between {@see self::probe()} and the facade. It holds the probe values too,
     * so it marks them like any other secret.
     *
     * @param  class-string<Facade>  $facade
     * @param  array<array-key, string>  $arguments
     *
     * @throws Throwable whatever the facade throws; {@see RedactionProbeReached} when it reached the root
     */
    private static function call(string $facade, string $method, #[SensitiveParameter] array $arguments): void
    {
        // Always callable through __callStatic; a direct call adds no frame of its own, which
        // call_user_func_array() would — holding every argument raw.
        $call = [$facade, $method];

        if (is_callable($call)) {
            $call(...$arguments);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $trace
     * @param  array<string, string>  $secrets  probe value => the argument it stands for
     * @param  array<string, string>  $harmless  probe value => the argument it stands for
     * @return list<string>
     */
    private static function inspect(FacadeSubject $subject, string $method, array $trace, string $call, array $secrets, array $harmless): array
    {
        $frame = self::facadeFrame($subject->facade, $method, $trace);

        if ($frame === null) {
            return ["{$call}: the trace holds no frame of {$subject->facade} with its arguments, so it proves "
                .'nothing either way. The check turns '.self::IGNORE_ARGS.' off for itself; the facade, or '
                .'something it calls, must not turn it back on.'];
        }

        $problems = [];

        foreach ($secrets as $value => $label) {
            foreach ($trace as $index => $entry) {
                if (self::holds($entry['args'] ?? [], $value)) {
                    $problems[] = "{$call}: {$label} is #[SensitiveParameter], but frame #{$index} "
                        .self::frameName($entry).' holds it raw.';

                    break;
                }
            }
        }

        foreach ($harmless as $value => $label) {
            if (! self::holds($frame, $value)) {
                $problems[] = "{$call}: {$label} is not #[SensitiveParameter], yet the facade frame hides it. Redact "
                    .'only the marked arguments: a facade that hides them all hides what a trace is read for.';
            }
        }

        return $problems;
    }

    /**
     * The arguments of the facade's own frame for this call: `__callStatic` (Laravel's or an
     * override) or a real static the facade declares under the method's name. Null when no such
     * frame captured its arguments.
     *
     * @param  array<int, array<string, mixed>>  $trace
     * @return array<array-key, mixed>|null
     */
    private static function facadeFrame(string $facade, string $method, array $trace): ?array
    {
        foreach ($trace as $entry) {
            $class = $entry['class'] ?? null;
            $function = $entry['function'] ?? null;
            $args = $entry['args'] ?? null;

            if (! is_string($class) || ! is_string($function) || ! is_array($args) || ! is_a($facade, $class, true)) {
                continue;
            }

            $function = strtolower($function);
            $forwarded = $args[0] ?? null;

            if ($function === strtolower($method)
                || ($function === '__callstatic' && is_string($forwarded) && strtolower($forwarded) === strtolower($method))) {
                return $args;
            }
        }

        return null;
    }

    /**
     * Whether a frame argument holds the value: a string containing it, or an array holding such
     * a string at any depth. Objects (a `SensitiveParameterValue` above all) are not opened.
     */
    private static function holds(mixed $value, string $needle, int $depth = 0): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }

        if (! is_array($value) || $depth > 32) {
            return false;
        }

        foreach ($value as $item) {
            if (self::holds($item, $needle, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function frameName(array $entry): string
    {
        $class = $entry['class'] ?? null;
        $type = $entry['type'] ?? null;
        $function = $entry['function'] ?? null;

        return (is_string($class) ? $class.(is_string($type) ? $type : '::') : '')
            .(is_string($function) ? $function : '{unknown}').'()';
    }

    private static function label(ReflectionParameter $parameter): string
    {
        return $parameter->isVariadic()
            ? 'variadic argument ...$'.$parameter->getName()
            : 'argument #'.($parameter->getPosition() + 1).' ($'.$parameter->getName().')';
    }
}
