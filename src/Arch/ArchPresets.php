<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Arch;

use PHPUnit\Architecture\Elements\ObjectDescription;

/**
 * Nine composable architecture presets, each grounded in a bug the fleet actually
 * shipped. Call one at the top level of a Pest arch file; it registers its own case.
 *
 * ```php
 * use RoundlyConsulting\Testing\Arch\ArchPresets;
 *
 * ArchPresets::strictTypes('RoundlyConsulting\Shops');
 * ArchPresets::finalByDefault('RoundlyConsulting\Shops\Actions', [SomeBase::class]);
 * ArchPresets::swappableModelsAreNotFinal([Shop::class => 'shops.shop_model']);
 * ```
 *
 * ## Exempt through `$ignoring`, never through `->ignoring()`
 *
 * Every preset takes an `$ignoring` parameter, and entries passed that way are rot-checked
 * by {@see self::exemptionsExist()}: an entry that silences nothing fails.
 *
 * A file may carry **as many exemption lists as it has presets**: each pin is registered
 * under the description of the preset that owns it, so it is unique wherever that preset is.
 * (It was not always so — a fixed pin description capped a file at one `$ignoring` list and
 * made the second a hard `TestAlreadyExist`, with `noDebuggingLeftovers` unable to fall back
 * to anything. See {@see self::exemptionsExist()}.)
 *
 * The presets built on Pest's arch layer ({@see self::strictTypes()},
 * {@see self::finalByDefault()}, {@see self::noLocalCryptoPrimitives()}) return the
 * underlying arch expectation, so Pest's fluent `->ignoring(...)` also composes on them —
 * **unchecked**. The same bogus entry fails through the parameter and passes green through
 * the fluent call, and the docs used to teach the fluent one. The six presets Pest's arch
 * layer cannot express ({@see self::swappableModelsAreNotFinal()},
 * {@see self::modelsResolveThroughSeam()}, {@see self::morphColumnsUseTheSeam()},
 * {@see self::runtimeRequireIsWhitelisted()}, {@see self::noDebuggingLeftovers()},
 * {@see self::modelsGoThroughTheFacade()}) register a token/reflection `it()` case and have no
 * fluent form at all.
 *
 * The gap is **stated rather than fixed**. `->ignoring()` is Pest's own method on an
 * `@internal` object whose `__destruct()` is what evaluates the expectation; intercepting it
 * would wedge this package between Pest and that destructor, and an arch case that silently
 * stops running is the exact failure this package exists to end. A documented gap beats a
 * check that lies.
 *
 * ## Exemptions match by PREFIX, so they silence more than they name
 *
 * Pest excludes an object when `str_starts_with($object->name, $exclude)` — a **string
 * prefix** test, not class identity (`pest-plugin-arch/src/Blueprint.php:103`). So
 * `Stripe::class` also exempts `StripeClient`, and `Metrics::class` also exempts
 * `MetricsManager`, with nothing reported either time. The rule stops applying to classes
 * their author still believes are covered — the same "cannot fail on real breakage" defect
 * as a stale exemption, arrived at from the opposite direction.
 *
 * Matching is on the **fully-qualified** name, so this only reaches classes sharing a
 * namespace *and* a name prefix: `Models\Role` cannot shadow `Database\Factories\RoleFactory`.
 *
 * {@see self::finalByDefault()} closes this by re-checking the shadowed classes itself —
 * see {@see ArchShadows} for why that beats making packages declare their shadows. The
 * recovery rides on the `$ignoring` parameter, giving the fluent form a second, sharper
 * cost: it is not merely unchecked, it also silently forfeits this. Fluent callers must
 * call {@see self::shadowedClassesAreFinal()} themselves.
 *
 * `finalByDefault` and `swappableModelsAreNotFinal` are deliberately in tension: the
 * first wants everything final, the second forbids `final` on a config-swappable model
 * (shipping it was a PHP fatal error seven times). Run both — exempt the swappable
 * models from the first via `$ignoring` and pin them with the second.
 */
final class ArchPresets
{
    /**
     * The crypto primitives that belong in `crypto-for-laravel`, never re-implemented
     * locally. Pest's arch `toUse` matches exact function names, so the openssl / sodium
     * / base64 families are enumerated rather than globbed. Public so a consumer can
     * reuse the exact list in a custom arch case.
     *
     * ## `hash_equals` is deliberately NOT here (removed 2026-07-17)
     *
     * It was, and two packages exempted it independently for the same reason —
     * `cosmos-foundation` (secret compare in the docs gate) and `media-library`
     * (`Media::verifyChecksum()`). Both were right, and the ban was wrong. Four reasons,
     * the last being the one that settles it:
     *
     *  1. **It is not a re-implementation.** This ban targets crypto *re-implemented*
     *     locally. `hash_equals()` **is** the primitive — PHP's canonical constant-time
     *     compare. Calling it is the correct thing; the ban fired on compliance.
     *  2. **There is no policy to centralize.** Every other entry carries a decision worth
     *     owning in one place — an algorithm, a key, a padding, an encoding.
     *     `hash_equals(string, string): bool` has no algorithm, no key, and no upgrade
     *     path. A `ConstantTime::equals()` wrapper can only ever be a pass-through, so the
     *     ban bought indirection and no audit point.
     *  3. **Its incentive gradient points at the vulnerability, uniquely on this list.**
     *     The cheapest way to get green without taking a runtime dep is `$a === $b` — a
     *     timing leak that reads in review as a harmless simplification. No other entry has
     *     an innocuous-looking escape: nobody quietly reduces `openssl_sign()` to an
     *     operator. media-library's note records it exempting "rather than silently
     *     rewritten" — the right outcome depended on an author resisting the test.
     *  4. **The ban cost more coverage than it bought.** `->ignoring()` is scoped to a
     *     *class*, not a function. Exempting `Media::class` for one correct `hash_equals`
     *     call blinds that entire class to the other nineteen primitives. The ban was
     *     buying a wrapper by trading away real coverage at exactly the security-sensitive
     *     call sites — and, for a package that doesn't already `require` crypto, the only
     *     alternative was a runtime dependency (`cosmos-foundation` ships in every cosmos
     *     service) taken on to avoid calling a builtin correctly.
     *
     * A package that DOES `require` crypto-for-laravel and wants `ConstantTime::equals()`
     * enforced should ban `hash_equals` in a bespoke rule — that is a "we own crypto, route
     * through it" policy, not a "don't re-implement crypto" one. Six packages already do
     * exactly that (`certificates`, `git`, `passkeys`, `purchases`, `refresh-tokens`,
     * `two-factor`), and none of them read this list.
     *
     * `hash` and `hash_hmac` stay: both take an algorithm, and that choice is the decision
     * crypto-for-laravel exists to own.
     *
     * @var list<string>
     */
    public const CRYPTO_PRIMITIVES = [
        'hash',
        'hash_hmac',
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
     * ## `$for` names the rule, and is what makes two exemption lists per file possible
     *
     * Pest keys a test by description within a file, so a pin registered under a fixed
     * description could exist **once per file** — a second preset carrying `$ignoring` was a
     * hard `Pest\Exceptions\TestAlreadyExist`, and four packages legitimately carry two
     * lists. `noDebuggingLeftovers` had no way out at all: it registers an `it()` case and
     * has no fluent form to fall back to.
     *
     * `$for` is the rule the list is attached to, and the presets pass their **own case
     * description** — so this pin is unique exactly when the preset that registered it is
     * unique, and adds no collision surface of its own. It is required rather than
     * defaulted: a default would just move the collision one call away, and the label is
     * what a developer reads when the pin fires.
     *
     * A counter (`#1`, `#2`) was rejected — it is not stable across edits: inserting a
     * preset renames every later case, breaking `--filter` and Pest's identity model.
     *
     * ## `$within` — an exemption must land where the rule looks
     *
     * An entry that names a real class **outside** the scanned namespace silences nothing
     * either: `finalByDefault('App\Shop\Support', [OpenAction::class])`, with `OpenAction` in
     * `App\Shop\Actions`, used to pass the existence check. The namespace-scoped presets pass
     * their namespace here, so such an entry fails too. What neither check can see is an entry
     * whose class already *complies* (an already-final class in a `finalByDefault` list) — it
     * is inert today and silently re-opens the ban the day the class stops complying, so keep
     * lists to the classes that need them.
     *
     * @param  list<string>  $exemptions
     * @param  string  $for  the rule the list exempts from, e.g. `no debugging leftovers`
     * @param  string|null  $within  the namespace the rule scans; each entry must match something under it
     */
    public static function exemptionsExist(array $exemptions, string $for, ?string $within = null): mixed
    {
        return it("preset: every arch exemption for {$for} still silences something", function () use ($exemptions, $within): void {
            ArchExemptions::assert($exemptions, $within);
        });
    }

    /**
     * Register the companion case that keeps a Pest-arch preset from passing over nothing: a
     * typo'd namespace, or one whose every object is exempted, fails here instead of reporting
     * green. See {@see ArchTargets}.
     *
     * @param  list<string>  $ignoring
     */
    private static function somethingToCheck(string $namespace, array $ignoring, string $label, bool $concreteClassesOnly = false): void
    {
        it("preset: {$label} has something to check", function () use ($namespace, $ignoring, $label, $concreteClassesOnly): void {
            ArchTargets::assertSomethingToCheck($namespace, $ignoring, $label, $concreteClassesOnly);
        });
    }

    /**
     * Every file under the namespace declares `declare(strict_types=1)`.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function strictTypes(string $namespace, array $ignoring = []): mixed
    {
        $label = 'strict types in '.$namespace;

        self::somethingToCheck($namespace, $ignoring, $label);

        return self::exempt(
            arch('preset: '.$label)
                ->expect($namespace)
                ->toUseStrictTypes(),
            $ignoring,
            $label,
            $namespace,
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
     * ## Exemptions reach further than they read — so the shadow is re-checked
     *
     * Pest matches exemptions by string **prefix**, not class identity, so `Stripe::class`
     * also silences `StripeClient`. Passing `$ignoring` therefore also registers
     * {@see self::shadowedClassesAreFinal()}, which re-applies this rule by reflection to
     * every class the exemptions silence without naming. A shadowed class that is already
     * final stays green; one that is not goes red, naming it. See {@see ArchShadows}.
     *
     * That recovery is only wired for the `$ignoring` **parameter**. Pest's fluent
     * `->ignoring()` cannot be intercepted (see the class docblock), so a package using the
     * fluent form must call {@see self::shadowedClassesAreFinal()} itself with the same
     * list — or, better, move the list into this parameter and get both checks for free.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function finalByDefault(string $namespace, array $ignoring = []): mixed
    {
        $label = 'classes are final by default in '.$namespace;

        $expectation = arch('preset: '.$label)
            ->expect($namespace)
            ->classes()
            ->toBeFinal();

        $expectation->mergeExcludeCallbacks([
            static fn (ObjectDescription $object): bool => class_exists($object->name)
                && $object->reflectionClass->isAbstract(),
        ]);

        if ($ignoring !== []) {
            self::shadowedClassesAreFinal($namespace, $ignoring);
        }

        self::somethingToCheck($namespace, $ignoring, $label, concreteClassesOnly: true);

        return self::exempt($expectation, $ignoring, $label, $namespace);
    }

    /**
     * Put back the coverage Pest's prefix-matched exemptions silently drop: every class an
     * exemption list silences **without naming** must still be final.
     *
     * {@see self::finalByDefault()} registers this for you when you pass `$ignoring`. Call
     * it directly only when the exemptions go through Pest's fluent `->ignoring()`, which
     * this package cannot see:
     *
     * ```php
     * $ignoring = [Github::class, Batch::class];
     *
     * ArchPresets::finalByDefault('RoundlyConsulting\Git')->ignoring($ignoring);
     * ArchPresets::shadowedClassesAreFinal('RoundlyConsulting\Git', $ignoring);
     * ```
     *
     * Bind the list to a variable or constant as above rather than repeating it: two copies
     * eventually disagree, and this check's whole job is to know what the real list reaches.
     *
     * @param  list<string>  $exemptions  the same list handed to `$ignoring` / `->ignoring()`
     */
    public static function shadowedClassesAreFinal(string $namespace, array $exemptions): mixed
    {
        return it("preset: classes hidden by a prefix-matched exemption are still final in {$namespace}", function () use ($namespace, $exemptions): void {
            ArchShadows::assertShadowedClassesAreFinal($namespace, $exemptions);
        });
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
     * `crypto-for-laravel`. Exempt an attestation/trust corner through `$ignoring`, which is
     * rot-checked; Pest's fluent `->ignoring()` also works here but is not.
     *
     * Note that an exemption is scoped to a **class**, not a function: exempting a class
     * to permit one primitive blinds it to all of {@see self::CRYPTO_PRIMITIVES}. Scope the
     * exemption to the smallest class that really needs it — and see that constant's
     * docblock for why `hash_equals` is not on the list.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function noLocalCryptoPrimitives(string $namespace, array $ignoring = []): mixed
    {
        $label = 'no local crypto primitives in '.$namespace;

        self::somethingToCheck($namespace, $ignoring, $label);

        return self::exempt(
            arch('preset: '.$label)
                ->expect($namespace)
                ->not
                ->toUse(self::CRYPTO_PRIMITIVES),
            $ignoring,
            $label,
            $namespace,
        );
    }

    /**
     * Models resolve their (possibly host-swapped) class only through the seam: no
     * `static::query()` / `self::query()` / `new static`, and the swap config literal
     * appears only inside `$seamDir`.
     *
     * **Declare `$modelKeys` unless every swap key you own is `model` / `models` / `*_model`
     * shaped.** Left empty, the stray-literal half infers swap keys from key *shape*, and so
     * covers only the keys named that way — `alerts` got one seam of four, and a stray
     * `config('alerts.alert')` stayed green. Widening the pattern cannot fix that: in alerts'
     * own config `silence` is a boolean and `alert` is a model, and they are the same shape.
     * Naming the keys removes the guess; declared keys are unioned with the inferred ones, so
     * declaring can only add coverage, and a declared key that matches nothing fails rather
     * than pretending to cover something. The list is the same one you already pass to
     * {@see swappableModelsAreNotFinal()}. See {@see ModelSeam} for the full reasoning.
     *
     * ```php
     * ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support', [
     *     'alerts.alert', 'alerts.health-check', 'alerts.silence-model', 'alerts.history.model',
     * ]);
     * ```
     *
     * @param  list<string>  $modelKeys  the swap keys to police; declared beats inferred
     */
    public static function modelsResolveThroughSeam(string $srcDir, string $seamDir = 'Support', array $modelKeys = []): mixed
    {
        return it("preset: models resolve through the {$seamDir} seam, not late static binding", function () use ($srcDir, $seamDir, $modelKeys): void {
            ModelSeam::assert($srcDir, $seamDir, $modelKeys);
        });
    }

    /**
     * Every morph column in `$migrationsDir` goes through the toolkit's `morphKey()` seam —
     * no raw `$table->morphs()` / `nullableMorphs()` / `uuid`|`ulid` variant, which hardcode
     * the id key type and break uuid/ulid hosts on a strict engine (SQLite type affinity hides
     * it). Scanned from source tokens, so a docblock or string literal mentioning `morphs(` is
     * not a false red, and `morphKey` itself never trips it. See {@see MorphSeam}.
     *
     * **Non-vacuous:** a missing directory, or one with zero scannable migration files, fails
     * rather than passing over nothing. Adopt this only on a package that actually ships
     * migrations; a package with no morph columns still passes (it scanned real files and found
     * no violation), and a package with no migrations at all must not call it.
     *
     * ## What this does NOT guard — the registration half of the same bug
     *
     * The fleet's morph migration also exposed a *runtime* fault: 18 of 22 providers called
     * `morphKey` from a migration but never registered the Blueprint macro, so a standalone
     * `php artisan migrate` fatals with `Blueprint::morphKey does not exist` (they "worked" only
     * because a sibling dependency registered it globally). That is deliberately **not** checked
     * here, because no static pin can check it honestly:
     *
     *  - A source grep for `registerBlueprintMacros(` is a **proxy for a runtime property**, and
     *    it lies in both directions — green when the call is present but guarded off at boot,
     *    red when a package registers the macro by any other wiring. It couples the pin to one
     *    exact convention and asserts a string, not that registration runs.
     *  - The honest check is **runtime**: boot *only this provider* (no siblings) and assert
     *    `Blueprint::hasMacro('morphKey')`, or that the migration does not fatal. But a consumer
     *    suite boots every provider, so a sibling's registration masks a missing one — the same
     *    masking the real-engine `toApplyOnConnection` pin has, since it loads dependency
     *    providers. Reproducing the standalone fatal needs an isolated single-provider boot that
     *    the standard harness actively defeats.
     *
     * A documented gap beats a check that lies. The mitigation is a boot-time guard in the
     * provider (call `registerBlueprintMacros()` in `boot()`), not a green arch pin that cannot
     * see whether it ran.
     */
    public static function morphColumnsUseTheSeam(string $migrationsDir): mixed
    {
        return it('preset: morph columns go through the morphKey seam, not raw morphs()', function () use ($migrationsDir): void {
            MorphSeam::assert($migrationsDir);
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
     * No debugging leftovers: dd / dump / ray / var_dump / print_r.
     *
     * Scanned from **source tokens**, not from Pest's arch layer. As an arch expectation this
     * was 0-for-every-run on `ray`: the arch layer only sees a dependency whose symbol
     * *exists*, and the `ray()` debugger package is not in our graph by policy — so `ray` was filtered out
     * before the ban ran and could never fail, while `dd`/`dump`/`var_dump`/`print_r` all bit.
     * The one debug tool a developer would realistically leave behind was the exact one the
     * preset could not catch. See {@see DebugLeftovers} for the measurement.
     *
     * `$srcDir` defaults to `src/` under the working directory — the same scope the arch
     * expectation effectively had (a `dd()` under `tests/` was never reported either), so the
     * 19 packages calling this bare keep the behaviour they had, minus the blind spot.
     *
     * @param  list<string>  $ignoring  class/namespace exemptions — pinned by {@see self::exemptionsExist()}
     * @param  string|null  $srcDir  the directory to scan; defaults to `<cwd>/src`
     */
    public static function noDebuggingLeftovers(array $ignoring = [], ?string $srcDir = null): mixed
    {
        $srcDir ??= getcwd().'/src';

        $label = 'no debugging leftovers';

        if ($ignoring !== []) {
            self::exemptionsExist($ignoring, $label);
        }

        return it('preset: '.$label, function () use ($srcDir, $ignoring): void {
            DebugLeftovers::assert($srcDir, $ignoring);
        });
    }

    /**
     * Model convenience methods and model traits reach behaviour through the **manager**,
     * never straight through an action: no class or trait under `{$namespace}\Models`,
     * `{$namespace}\Concerns` or `{$namespace}\Traits`, no Eloquent model anywhere under
     * `{$namespace}` (per-area layouts like `Shops\Cart\Cart` included), and no package trait
     * such a model uses (recursively) may reference anything in `{$namespace}\Actions`.
     * `{$namespace}\Actions` and `{$namespace}\Testing` are never scanned. A trait that calls
     * `app(LikePost::class)` is invisible to `Likes::fake()`, which swaps the manager — the fake
     * then misses every call made through the model. See {@see ModelsThroughFacade}.
     *
     * ```php
     * ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Likes');
     * ```
     *
     * **Non-vacuous:** if no model exists anywhere under the namespace and none of the three
     * namespaces holds a class, the case fails — a package with no models or model traits must
     * not call this preset. Scanned from source tokens, so
     * an action named only in a docblock is not a violation.
     *
     * Exemptions go through `$ignoring` (class or namespace names, prefix-matched) and are pinned
     * twice: {@see self::exemptionsExist()} fails a name that does not exist, and the case itself
     * fails an entry that exempts no violating class.
     *
     * @param  list<string>  $ignoring  exemptions — pinned by {@see self::exemptionsExist()}
     */
    public static function modelsGoThroughTheFacade(string $namespace, array $ignoring = []): mixed
    {
        $namespace = trim($namespace, '\\');
        $label = 'models go through the facade in '.$namespace;

        if ($ignoring !== []) {
            self::exemptionsExist($ignoring, $label);
        }

        return it('preset: '.$label, function () use ($namespace, $ignoring): void {
            ModelsThroughFacade::assert($namespace, $ignoring);
        });
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
     * `$for` is the caller's own case description, so the pin it registers is unique
     * wherever the preset itself is — see {@see self::exemptionsExist()} for why that
     * property, rather than a counter, is what lets one file carry two exemption lists.
     *
     * @param  list<string>  $ignoring
     * @param  string  $for  the registering preset's own case description, minus `preset: `
     * @param  string  $within  the namespace the preset scans — every entry must match under it
     */
    private static function exempt(mixed $expectation, array $ignoring, string $for, string $within): mixed
    {
        if ($ignoring === []) {
            return $expectation;
        }

        self::exemptionsExist($ignoring, $for, $within);

        return $expectation->ignoring($ignoring); // @phpstan-ignore-line
    }
}
