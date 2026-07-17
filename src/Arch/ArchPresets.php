<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use PHPUnit\Architecture\Elements\ObjectDescription;

/**
 * Seven composable architecture presets, each grounded in a bug the fleet actually
 * shipped. Call one at the top level of a Pest arch file; it registers its own case.
 *
 * ```php
 * use RoundlyConsulting\Testing\Arch\ArchPresets;
 *
 * ArchPresets::strictTypes('RoundlyConsulting\Shops');
 * ArchPresets::finalByDefault('RoundlyConsulting\Shops\Actions')->ignoring(SomeBase::class);
 * ArchPresets::swappableModelsAreNotFinal([Shop::class => 'shops.shop_model']);
 * ```
 *
 * The presets built on Pest's arch layer ({@see self::strictTypes()},
 * {@see self::finalByDefault()}, {@see self::noLocalCryptoPrimitives()},
 * {@see self::noDebuggingLeftovers()}) return the underlying arch expectation, so
 * `->ignoring(...)` composes exactly as it does on a hand-written `arch()`. The three
 * presets Pest's arch layer cannot express ({@see self::swappableModelsAreNotFinal()},
 * {@see self::modelsResolveThroughSeam()}, {@see self::runtimeRequireIsWhitelisted()})
 * register a token/reflection `it()` case instead.
 *
 * `finalByDefault` and `swappableModelsAreNotFinal` are deliberately in tension: the
 * first wants everything final, the second forbids `final` on a config-swappable model
 * (shipping it was a PHP fatal error seven times). Run both — exempt the swappable
 * models from the first with `->ignoring(...)` and pin them with the second.
 */
final class ArchPresets
{
    /**
     * The crypto primitives that belong in `crypto-for-laravel`, never re-implemented
     * locally. Pest's arch `toUse` matches exact function names, so the openssl / sodium
     * / base64 families are enumerated rather than globbed. Public so a consumer can
     * reuse the exact list in a custom arch case.
     *
     * @var list<string>
     */
    public const CRYPTO_PRIMITIVES = [
        'hash',
        'hash_hmac',
        'hash_equals',
        'hash_pbkdf2',
        'openssl_encrypt',
        'openssl_decrypt',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_pkey_get_public',
        'openssl_pkey_get_private',
        'openssl_pkey_get_details',
        'openssl_random_pseudo_bytes',
        'sodium_crypto_sign_verify_detached',
        'sodium_crypto_sign',
        'sodium_crypto_generichash',
        'random_bytes',
        'random_int',
        'base64_encode',
        'base64_decode',
    ];

    /**
     * Assert that every entry in an exemption list still silences something.
     *
     * Pest's `->ignoring(...)` accepts any string and never checks it, so a typo
     * (`Types\Metric` for `Facades\Metric`) or an exemption that outlived its code is a
     * **silent no-op** — the ban then applies where you believe it does not, or has a hole
     * nobody can see. Pass exemptions through the `$ignoring` parameter of the presets
     * below (or call this directly) to pin them.
     *
     * @param  list<string>  $exemptions
     */
    public static function exemptionsExist(array $exemptions): mixed
    {
        return it('preset: arch exemptions all still silence something', function () use ($exemptions): void {
            ArchExemptions::assert($exemptions);
        });
    }

    /**
     * Every file under the namespace declares `declare(strict_types=1)`.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function strictTypes(string $namespace, array $ignoring = []): mixed
    {
        return self::exempt(
            arch('preset: strict types in '.$namespace)
                ->expect($namespace)
                ->toUseStrictTypes(),
            $ignoring,
        );
    }

    /**
     * Classes are final by default. Exempt the intentional extension points (swappable
     * models, deliberate bases) with `$ignoring`.
     *
     * **Abstract** classes are excluded automatically rather than needing an exemption
     * each: `abstract final` is a PHP fatal, so an abstract class cannot satisfy this ban
     * on any codebase. Flagging one was a false positive by construction — metrics carried
     * seven exemptions for it — and every one of those exemptions was a hole in the ban
     * for the concrete classes they were written next to.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function finalByDefault(string $namespace, array $ignoring = []): mixed
    {
        $expectation = arch('preset: classes are final by default in '.$namespace)
            ->expect($namespace)
            ->classes()
            ->toBeFinal();

        $expectation->mergeExcludeCallbacks([
            static fn (ObjectDescription $object): bool => class_exists($object->name)
                && $object->reflectionClass->isAbstract(),
        ]);

        return self::exempt($expectation, $ignoring);
    }

    /**
     * The deliberate counter-weight to {@see self::finalByDefault()}: each mapped model
     * must be non-final AND the config key must default to it.
     *
     * @param  array<class-string, string>  $map  [Shop::class => 'shops.shop_model']
     */
    public static function swappableModelsAreNotFinal(array $map): mixed
    {
        return it('preset: swappable models are non-final and their config defaults point at them', function () use ($map): void {
            SwappableModels::assert($map);
        });
    }

    /**
     * No crypto primitive is re-implemented locally — primitives live in
     * `crypto-for-laravel`. Exempt an attestation/trust corner with `->ignoring(...)`.
     */
    /**
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function noLocalCryptoPrimitives(string $namespace, array $ignoring = []): mixed
    {
        return self::exempt(
            arch('preset: no local crypto primitives in '.$namespace)
                ->expect($namespace)
                ->not
                ->toUse(self::CRYPTO_PRIMITIVES),
            $ignoring,
        );
    }

    /**
     * Models resolve their (possibly host-swapped) class only through the seam: no
     * `static::query()` / `self::query()` / `new static`, and the swap config literal
     * appears only inside `$seamDir`.
     */
    public static function modelsResolveThroughSeam(string $srcDir, string $seamDir = 'Support'): mixed
    {
        return it("preset: models resolve through the {$seamDir} seam, not late static binding", function () use ($srcDir, $seamDir): void {
            ModelSeam::assert($srcDir, $seamDir);
        });
    }

    /**
     * The runtime dependency policy as a test: `require` holds only whitelisted vendors.
     *
     * @param  list<string>  $alsoAllow
     */
    public static function runtimeRequireIsWhitelisted(string $composerJson, array $alsoAllow = []): mixed
    {
        return it('preset: runtime require holds only whitelisted vendors', function () use ($composerJson, $alsoAllow): void {
            RuntimeRequires::assert($composerJson, $alsoAllow);
        });
    }

    /**
     * No debugging leftovers anywhere: dd / dump / ray / var_dump / print_r.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function noDebuggingLeftovers(array $ignoring = []): mixed
    {
        return self::exempt(
            arch('preset: no debugging leftovers')
                ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
                ->not
                ->toBeUsed(),
            $ignoring,
        );
    }

    /**
     * Apply an exemption list to a Pest arch expectation **and** register the pin that
     * keeps it honest.
     *
     * Pest's own `->ignoring()` cannot be made strict from the outside: it stores whatever
     * strings it is handed on an `@internal` expectation object, and it legitimately
     * accepts namespaces as well as class names — so there is no interception point, and
     * "this is not a class" is not the same as "this is stale". Routing exemptions through
     * a parameter is the honest alternative: the list is a value we can check before
     * handing it on.
     *
     * @param  list<string>  $ignoring
     */
    private static function exempt(mixed $expectation, array $ignoring): mixed
    {
        if ($ignoring === []) {
            return $expectation;
        }

        self::exemptionsExist($ignoring);

        return $expectation->ignoring($ignoring); // @phpstan-ignore-line
    }
}
