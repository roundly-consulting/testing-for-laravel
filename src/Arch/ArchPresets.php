<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

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
     * Every file under the namespace declares `declare(strict_types=1)`.
     */
    public static function strictTypes(string $namespace): mixed
    {
        return arch('preset: strict types in '.$namespace)
            ->expect($namespace)
            ->toUseStrictTypes();
    }

    /**
     * Classes are final by default. Exempt the intentional extension points (abstract
     * bases, swappable models) with `->ignoring(...)`.
     */
    public static function finalByDefault(string $namespace): mixed
    {
        return arch('preset: classes are final by default in '.$namespace)
            ->expect($namespace)
            ->classes()
            ->toBeFinal();
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
    public static function noLocalCryptoPrimitives(string $namespace): mixed
    {
        return arch('preset: no local crypto primitives in '.$namespace)
            ->expect($namespace)
            ->not
            ->toUse(self::CRYPTO_PRIMITIVES);
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
     */
    public static function noDebuggingLeftovers(): mixed
    {
        return arch('preset: no debugging leftovers')
            ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
            ->not
            ->toBeUsed();
    }
}
