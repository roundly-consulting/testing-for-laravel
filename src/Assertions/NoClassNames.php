<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins that a payload a client receives carries no PHP class name — the fleet rule since
 * 2026-10-18: most host apps have no morph map, so a `*_type` column **is** `App\Models\User`,
 * and shipping it leaks the host's namespace layout into its API.
 *
 * The subject is read as the client reads it: a JSON string is decoded, a `JsonResource` and a
 * response are rendered to their body first (wrapping, `with()` and `additional()` included),
 * an array is taken as the payload. Then every key and every string value is checked, at any
 * depth:
 *
 *  - a namespaced class name anywhere — `App\Models\User`, `\App\Models\User`, a JSON-encoded
 *    copy inside a string (`App\\Models\\User`), one inside prose (`No query results for model
 *    [App\Models\User]`). A StudlyCase `Vendor\Name` shape is enough; a lowercase one counts
 *    when the class really exists;
 *  - a `*_type` key holding the name of a class that exists, even with no namespace to give it
 *    away (a morph column on a global class).
 *
 * A reference resource's own `{id, type: "User"}` passes: `type` is not a `*_type` column and a
 * basename has no namespace. A morph **alias** (`"commentable_type": "post"`) passes too.
 *
 * Never green over nothing: an empty string, `{}`, `[]`, `null`, an empty resource or a
 * response with no body fails — a payload with no value in it proves nothing rendered.
 */
final class NoClassNames
{
    /**
     * A StudlyCase namespaced name: two or more segments, each starting upper-case, joined by one
     * or more backslashes (more than one when the name was JSON-encoded into a string).
     */
    private const string STUDLY_CLASS = '/(?<![\w\\\\])\\\\*[A-Z][A-Za-z0-9_]*(?:\\\\+[A-Z][A-Za-z0-9_]*)+/';

    /**
     * Any backslash-joined identifier chain — only a leak when the class really exists.
     */
    private const string ANY_CHAIN = '/(?<![\w\\\\])\\\\*[A-Za-z_][A-Za-z0-9_]*(?:\\\\+[A-Za-z_][A-Za-z0-9_]*)+/';

    /** @var list<string> */
    private array $leaks = [];

    private int $values = 0;

    private function __construct() {}

    public static function assert(mixed $subject): void
    {
        $payload = self::payload($subject);

        $check = new self;
        $check->walk($payload, '');

        Assert::assertGreaterThan(
            0,
            $check->values,
            'There is nothing to check: the payload is empty, so "it exposes no class names" would '
            .'pass without anything having rendered. Assert on the populated payload.',
        );

        Assert::assertSame(
            [],
            $check->leaks,
            "The payload exposes PHP class names to the client:\n  - ".implode("\n  - ", $check->leaks)
            ."\nRender each related model through a reference resource holding {id, type: class_basename}, "
            .'never a morph type or `::class` value.',
        );
    }

    /**
     * The payload as the client decodes it.
     */
    private static function payload(mixed $subject): mixed
    {
        if ($subject instanceof JsonResource) {
            $subject = $subject->toResponse(self::request());
        }

        if ($subject instanceof TestResponse) {
            $subject = $subject->baseResponse;
        }

        if ($subject instanceof Response) {
            $subject = (string) $subject->getContent();
        }

        if (is_array($subject)) {
            return $subject;
        }

        if (! is_string($subject)) {
            throw new InvalidArgumentException(
                'toExposeNoClassNames() reads a JSON string, an array, a JsonResource or a response; got '
                .get_debug_type($subject).'.',
            );
        }

        if (trim($subject) === '') {
            return null;
        }

        try {
            return json_decode($subject, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'toExposeNoClassNames() was given a string that is not JSON ('.$exception->getMessage().'): '
                .'pass the payload the client receives.',
                previous: $exception,
            );
        }
    }

    private static function request(): Request
    {
        $request = function_exists('app') && app()->bound('request') ? app('request') : null;

        return $request instanceof Request ? $request : Request::create('/');
    }

    private function walk(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $childPath = $path === '' ? (string) $key : $path.'.'.$key;

                if (is_string($key)) {
                    $this->checkString($key, "the key `{$childPath}`");
                }

                if (is_string($key) && is_string($item) && str_ends_with($key, '_type')) {
                    $this->values++;
                    $this->checkString($item, "the morph type column `{$childPath}`");
                    $this->checkMorphType($item, $childPath);

                    continue;
                }

                $this->walk($item, $childPath);
            }

            return;
        }

        if ($path === '' && $value === null) {
            return;
        }

        $this->values++;

        if (is_string($value)) {
            $this->checkString($value, $path === '' ? 'the payload' : "`{$path}`");
        }
    }

    private function checkString(string $value, string $where): void
    {
        if (! str_contains($value, '\\')) {
            return;
        }

        $found = [];

        if (preg_match_all(self::STUDLY_CLASS, $value, $studly) > 0) {
            $found = $studly[0];
        }

        if (preg_match_all(self::ANY_CHAIN, $value, $chains) > 0) {
            foreach ($chains[0] as $chain) {
                if (self::exists((string) preg_replace('/\\\\+/', '\\', ltrim($chain, '\\')))) {
                    $found[] = $chain;
                }
            }
        }

        foreach (array_unique($found) as $name) {
            $this->leaks[] = "{$where} holds the class name `".ltrim($name, '\\').'`';
        }
    }

    /**
     * A `*_type` column holding a real class with no namespace to give it away.
     */
    private function checkMorphType(string $value, string $path): void
    {
        if (! str_contains($value, '\\') && self::exists($value)) {
            $this->leaks[] = "the morph type column `{$path}` holds the class name `{$value}`";
        }
    }

    private static function exists(string $name): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $name) !== 1) {
            return false;
        }

        return class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name);
    }
}
