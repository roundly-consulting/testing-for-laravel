<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ArchExemptions;
use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Testing\Arch\ModelSeam;
use RoundlyConsulting\Testing\Arch\RuntimeRequires;

$archFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/'.$path;

// ---------------------------------------------------------------------------
// noLocalCryptoPrimitives — the preset is a Pest arch case over the ban list; its
// live green run against a clean namespace is in ArchPresetsGreenTest. Pest's arch
// layer only reliably scans src-mapped namespaces, so the bite is proven
// deterministically here: the ban list contains exactly the primitive the fixture
// re-implements, so pointing the preset at that code goes red.
// ---------------------------------------------------------------------------

it('bans exactly the openssl primitive a local verifier re-implements', function () use ($archFixture): void {
    $source = (string) file_get_contents($archFixture('crypto/LocalVerifier.php'));

    $called = [];

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_STRING) {
            $called[] = $token[1];
        }
    }

    expect(array_values(array_intersect($called, ArchPresets::CRYPTO_PRIMITIVES)))
        ->not->toBeEmpty()
        ->toContain('openssl_verify');
});

it('does not ban hash_equals, which is the correct primitive rather than a copy of one', function (): void {
    // Pinned, with the reasoning in the CRYPTO_PRIMITIVES docblock. `hash_equals()` IS PHP's
    // constant-time compare, so the ban fired on correct code; it carries no algorithm or key
    // to centralize; the cheap way to satisfy it is `$a === $b`, a timing leak that reads as a
    // harmless simplification; and since `->ignoring()` is class-scoped, exempting a class for
    // one correct call blinded it to every other primitive. Two packages exempted it
    // independently before it was removed. Re-adding it must be a decision, not a merge.
    expect(ArchPresets::CRYPTO_PRIMITIVES)
        ->not->toContain('hash_equals')
        // The neighbours stay: both take an algorithm, and that choice is what crypto owns.
        ->toContain('hash')
        ->toContain('hash_hmac');
});

// ---------------------------------------------------------------------------
// modelsResolveThroughSeam — green, then proves-it-bites.
// ---------------------------------------------------------------------------

it('accepts a package that resolves models only through the seam', function () use ($archFixture): void {
    ModelSeam::assert($archFixture('seam/green'));
});

it('rejects a static::query() helper that bypasses the configured seam', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/static-query')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a self::query() helper that bypasses the configured seam', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/self-query')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a new static instantiation that bypasses the configured seam', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/new-static')))
        ->toThrow(AssertionFailedError::class);
});

it('accepts a model calling self::query() from an instance method', function () use ($archFixture): void {
    // Reported on refresh-tokens, jwt and connections. `self::` is a FORWARDING call: inside
    // an instance method late static binding survives it, so `self::query()` already builds
    // for `$this`'s runtime class — the configured one. Verified empirically rather than
    // assumed: `(new Sub)->viaSelf()` returns `Sub`, not `Base`.
    //
    // This is the second distinct false positive in this ban, both from matching a token
    // shape instead of the semantics. The rule is: flag late static resolution only where the
    // called class is NOT already pinned by a `$this` — i.e. in a static context.
    ModelSeam::assert($archFixture('seam/instance-self-query'));
});

it('accepts a non-model class using new static as a named constructor', function () use ($archFixture): void {
    // Reported on `options`, whose BaseOption::for()/make() are built on `new static` —
    // BaseOption is the abstract class hosts extend to define a setting, not a model, and
    // `new static` is the only way ThemeOption::for($user) returns a ThemeOption. The
    // preset's docblock scoped the rule to models; the implementation did not.
    ModelSeam::assert($archFixture('seam/non-model-query'));
});

it('accepts a non-model class calling its own static query() helper', function () use ($archFixture): void {
    // The regression pin. The ban matched the bare token `self::query(`, which is only a
    // seam bypass when query() is Eloquent's. This fixture is not a model: `self::query()`
    // resolves to its own private helper, which itself goes through the seam. It was
    // flagged in two packages (approvals' ApprovalChecker, jwt), and the preset ships as
    // an it() case with no ->ignoring() escape — so the verdict was unappealable on
    // correct code.
    ModelSeam::assert($archFixture('seam/non-model-query'));
});

it('still rejects a seam bypass on a model that extends an intermediate base', function () use ($archFixture): void {
    // Guard the guard for the fix above: gating the ban on "is a model" must not become a
    // way to escape it. This model reaches Model only through a package base class, so a
    // token-level `extends Model` gate would silently stop banning it.
    expect(fn () => ModelSeam::assert($archFixture('seam/subclassed-model')))
        ->toThrow(AssertionFailedError::class);
});

it('rejects a swap config literal read outside the seam directory', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/stray-literal')))
        ->toThrow(AssertionFailedError::class);
});

it('fails on a missing source directory rather than passing vacuously', function () use ($archFixture): void {
    expect(fn () => ModelSeam::assert($archFixture('seam/does-not-exist')))
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// modelsResolveThroughSeam — $modelKeys, and the shape floor it exists to lift.
//
// The `alerts-shape` fixture is alerts' real key naming: `alerts.alert` and
// `alerts.health-check` carry no `model` segment, `alerts.silence-model` is hyphenated
// where the inference tests for an underscore, and only `alerts.history.model` is
// conventionally shaped. Its Support/RecordResolver reads all four legitimately; its
// Actions/StrayReader reads the three unconventional ones outside the seam.
// ---------------------------------------------------------------------------

it('pins the shape floor: an unconventionally-named stray is invisible undeclared', function () use ($archFixture): void {
    // NOT an endorsement — this is the defect, pinned. Three stray reads sit outside the
    // seam and the preset is green, because no `model` segment means no inferred key. This
    // is why $modelKeys exists, and why the docblock calls inference a floor. Should a
    // future change make this red, that is an improvement — but it must be a deliberate
    // one, and this case is what forces the conversation.
    ModelSeam::assert($archFixture('seam/alerts-shape'));

    expect(true)->toBeTrue();
});

it('rejects unconventionally-named stray literals once they are declared', function () use ($archFixture): void {
    // The bite. The same fixture, the same strays, the only change being that the keys are
    // named rather than guessed at.
    //
    // Pinned to the *stray* message, not merely to AssertionFailedError: the rot check
    // raises that same class from the same call, so a bare ->toThrow(AssertionFailedError)
    // here passes with the declared-key half deleted — it was verified doing exactly that.
    expect(fn () => ModelSeam::assert($archFixture('seam/alerts-shape'), 'Support', [
        'alerts.alert',
        'alerts.health-check',
        'alerts.silence-model',
    ]))
        ->toThrow(AssertionFailedError::class, 'competing resolution path');
});

it('names every declared stray it found, not just the first', function () use ($archFixture): void {
    // A message that reported one of three would send a package back for three rounds.
    try {
        ModelSeam::assert($archFixture('seam/alerts-shape'), 'Support', [
            'alerts.alert',
            'alerts.health-check',
            'alerts.silence-model',
        ]);
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('alerts.alert')
            ->toContain('alerts.health-check')
            ->toContain('alerts.silence-model')
            ->toContain('StrayReader.php');

        return;
    }

    $this->fail('Declared stray literals did not fail the seam assertion.');
});

it('accepts a declared key that only the seam reads', function () use ($archFixture): void {
    // Proves the bite above is about *where* the key is read, not merely that a key was
    // declared: `alerts.history.model` is read only by Support/RecordResolver.
    ModelSeam::assert($archFixture('seam/alerts-shape'), 'Support', ['alerts.history.model']);

    expect(true)->toBeTrue();
});

it('unions declared keys with inferred ones rather than replacing them', function () use ($archFixture): void {
    // Were a declaration to REPLACE the inferred set, declaring an unrelated key here would
    // drop the conventionally-named strays this fixture is built on and pass. Coverage must
    // be monotone in the declaration: declaring can only ever add.
    expect(fn () => ModelSeam::assert($archFixture('seam/stray-literal'), 'Support', ['arch.record_model']))
        ->toThrow(AssertionFailedError::class, 'widgets.model');
});

it('fails a declared key that appears nowhere in the source', function () use ($archFixture): void {
    // Rot-proofing. `alerts.silence_model` is the underscore typo of the fixture's real
    // hyphenated `alerts.silence-model` — exactly the confusion that motivates declaring —
    // and a declaration that matches nothing is a hole shaped like coverage.
    expect(fn () => ModelSeam::assert($archFixture('seam/alerts-shape'), 'Support', ['alerts.silence_model']))
        ->toThrow(AssertionFailedError::class, 'police nothing');
});

// ---------------------------------------------------------------------------
// runtimeRequireIsWhitelisted — green, then proves-it-bites.
// ---------------------------------------------------------------------------

it('accepts a require block of only whitelisted vendors', function () use ($archFixture): void {
    RuntimeRequires::assert($archFixture('composer/whitelisted.json'));
});

it('rejects a disallowed third-party vendor in require', function () use ($archFixture): void {
    expect(fn () => RuntimeRequires::assert($archFixture('composer/disallowed.json')))
        ->toThrow(AssertionFailedError::class);
});

it('accepts a would-be-disallowed vendor once it is explicitly allowed', function () use ($archFixture): void {
    RuntimeRequires::assert($archFixture('composer/disallowed.json'), [
        'acme/media-library',
        'guzzlehttp/guzzle',
    ]);
});

// ---------------------------------------------------------------------------
// exemptionsExist — an arch exemption that silences nothing must fail, the same
// standard the config contract holds allowUnread/allowUnshipped to.
// ---------------------------------------------------------------------------

it('accepts an exemption naming a class that really exists', function (): void {
    ArchExemptions::assert([ArchPresets::class, ModelSeam::class]);
});

it('accepts an exemption naming a namespace that really holds code', function (): void {
    // ->ignoring() takes namespaces as well as class names, so "not a class" is not the
    // same as "stale" — checking only for classes would reject a legitimate exemption.
    ArchExemptions::assert(['RoundlyConsulting\Testing\Arch', 'RoundlyConsulting\Testing\Assertions']);
});

it('rejects an exemption that silences nothing', function (): void {
    // The shipped bug: `Types\Metric` where the real class is `Facades\Metric`. `::class`
    // on a non-existent class resolves to a string at compile time, so PHP never
    // complained and neither did Pest.
    expect(fn () => ArchExemptions::assert(['RoundlyConsulting\Testing\Types\Metric']))
        ->toThrow(AssertionFailedError::class, 'silence nothing');
});

it('rejects an empty exemption entry', function (): void {
    expect(fn () => ArchExemptions::assert(['']))
        ->toThrow(AssertionFailedError::class);
});

it('names every stale exemption, not just the first', function (): void {
    expect(fn () => ArchExemptions::assert(['App\Nope\One', ArchPresets::class, 'App\Nope\Two']))
        ->toThrow(AssertionFailedError::class, 'App\Nope\One, App\Nope\Two');
});

it('still rejects a seam bypass inside a closure in a static method', function () use ($archFixture): void {
    // Guard the guard for the instance-method fix: a closure declared in a static method has
    // no $this either, so gating on "is the innermost function static" would let the bypass
    // back in silently. Nesting taints outward.
    expect(fn () => ModelSeam::assert($archFixture('seam/static-closure-query')))
        ->toThrow(AssertionFailedError::class);
});

// ---------------------------------------------------------------------------
// The $ignoring parameter is checked; Pest's fluent ->ignoring() is not.
//
// Not a defect this package can fix, so it is pinned as the documented gap it is.
// `->ignoring()` is Pest's own method on an @internal object whose __destruct() evaluates
// the expectation — wrapping it to intercept the call would put this package between Pest
// and that destructor, and an arch case that silently stops running is the very failure the
// whole package exists to end. So the README and docblocks teach the parameter instead, and
// these two cases keep that teaching honest: if Pest ever starts checking the fluent form,
// the second one fails and the docs get revisited.
// ---------------------------------------------------------------------------

it('rot-checks an exemption passed through the $ignoring parameter', function (): void {
    expect(fn () => ArchExemptions::assert(['RoundlyConsulting\Bogus\DoesNotExist']))
        ->toThrow(AssertionFailedError::class, 'silence nothing');
});

it('accepts a namespace exemption, which is why liveness is not a bare class_exists', function (): void {
    // `->ignoring()` legitimately takes namespaces, so the rot-check has to as well —
    // rejecting one for being a namespace would be its own false positive.
    ArchExemptions::assert(['RoundlyConsulting\Testing\Arch']);

    expect(true)->toBeTrue();
});

it('still rejects a seam bypass in a class that declares an abstract method', function () use ($archFixture): void {
    // A bodyless declaration must not corrupt static-context tracking: if it did, the static
    // helper below it would read as instance-scoped and the ban would go quietly green.
    expect(fn () => ModelSeam::assert($archFixture('seam/abstract-method')))
        ->toThrow(AssertionFailedError::class);
});

it('flags static::where()/firstOrCreate() in a static model method and new self', function () use ($archFixture): void {
    // Model::__callStatic forwards any method it does not declare to a fresh query on the class
    // the caller NAMED — the same late-binding bypass as static::query(), spelled differently.
    foreach (['static-where', 'static-first-or-create', 'self-create', 'new-self'] as $fixture) {
        expect(fn () => ModelSeam::assert($archFixture("seam/{$fixture}")))
            ->toThrow(AssertionFailedError::class, 'Models/Tag.php');
    }
});

it('accepts static hook registration and own helpers in booted()', function () use ($archFixture): void {
    // static::creating(), static::addGlobalScope(), self::saving() are Model's own statics; a
    // class's own static helper is its own. Nothing is forwarded to a query, so nothing bypasses
    // the seam — 35 fleet booted() hooks have exactly this shape.
    ModelSeam::assert($archFixture('seam/booted-hooks'));
});
