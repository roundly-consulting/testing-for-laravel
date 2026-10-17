<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which static calls on an Eloquent model build a query on the class the caller **named**.
 *
 * Model's `__callStatic` forwards any method the class does not declare to a fresh query on
 * that class, so `X::where()`, `X::create()` and `X::firstOrCreate()` all start one. So do
 * Model's own query entry points (`query()`, `all()`, `with()`, `on()`, `destroy()`, …), which
 * Model declares and therefore never forwards. Model's other statics — event hooks
 * (`creating()`), `observe()`, `addGlobalScope()` — build nothing.
 *
 * @internal
 */
final class QueryForwarding
{
    /**
     * Model's own static entry points that build a query on the named class. Lowercased.
     *
     * @var list<string>
     */
    public const array QUERY_ENTRY_POINTS = ['query', 'all', 'with', 'on', 'onwriteconnection', 'destroy'];

    /**
     * The scopes `SoftDeletes` registers as builder **macros**: declared on no class, so
     * reflection over the builders cannot list them, yet `X::withTrashed()` forwards to a query
     * all the same. Lowercased.
     *
     * @var list<string>
     */
    public const array SOFT_DELETE_MACROS = [
        'withtrashed', 'withouttrashed', 'onlytrashed', 'restore', 'restoreorcreate', 'createorrestore',
    ];

    /** @var list<string>|null lowercased public methods of the Eloquent and query builders */
    private static ?array $builderMethods = null;

    /**
     * Whether `$model::$method(` builds a query on `$model`.
     *
     * A loadable model answers exactly: a method it does not declare (its own, a trait's, a
     * base class's, Model's) is forwarded to a query — scopes and macros included. A model that
     * cannot be loaded falls back to the builders' public methods plus the soft-delete macros,
     * minus what Model declares.
     */
    public static function startsQuery(string $model, string $method): bool
    {
        $method = strtolower($method);

        if (in_array($method, self::QUERY_ENTRY_POINTS, true)) {
            return true;
        }

        if (class_exists($model)) {
            return ! method_exists($model, $method);
        }

        if (method_exists(Model::class, $method)) {
            return false;
        }

        return in_array($method, self::builderMethods(), true) || in_array($method, self::SOFT_DELETE_MACROS, true);
    }

    /**
     * @return list<string>
     */
    public static function builderMethods(): array
    {
        if (self::$builderMethods === null) {
            $methods = [];

            foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
                foreach ((new ReflectionClass($builder))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                    if (! str_starts_with($method->getName(), '__')) {
                        $methods[] = strtolower($method->getName());
                    }
                }
            }

            self::$builderMethods = array_values(array_unique($methods));
        }

        return self::$builderMethods;
    }
}
