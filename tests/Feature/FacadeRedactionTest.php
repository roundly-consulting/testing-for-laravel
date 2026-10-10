<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Assert;
use RoundlyConsulting\Testing\Assertions\Facades\RedactionProbe;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Broken\StringKeyed;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Locker as LockerContract;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\BluntVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\Keyring;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\Locker;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\MuteVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\RefusingVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\RelayVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\ShadowVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\SilentVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\StaticVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\StockVault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Facades\Vault;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\KeyringManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\LockerManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\ShadowManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\VaultManager;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Widgets\Facades\Widgets;
use RoundlyConsulting\Testing\Tests\Support\RedactionTestCase;

uses(RedactionTestCase::class);

/**
 * The failure message of a closure that must fail the expectation.
 */
function redactionFailure(Closure $check): string
{
    try {
        $check();
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    throw new LogicException('The expectation passed, but it had to fail.');
}

// ---------------------------------------------------------------------------
// Green: exactly the marked arguments are hidden, in every frame.
// ---------------------------------------------------------------------------

it('accepts the selective trait', function (): void {
    expect(Vault::class)->toRedactSensitiveArguments(methods: 4);
});

it('accepts real statics that carry their own attributes', function (): void {
    expect(StaticVault::class)->toRedactSensitiveArguments(methods: 4);
});

it('accepts a facade over a contract whose implementation marks the same parameters', function (): void {
    expect(Locker::class)->toRedactSensitiveArguments(methods: 1);
});

it('chains with the other facade expectations', function (): void {
    expect(Vault::class)
        ->toBeFakeable()
        ->toRedactSensitiveArguments(methods: 4)
        ->toBe(Vault::class);
});

it('is reachable through the static Assert twin', function (): void {
    Assert::facadeRedactsSensitiveArguments(Vault::class, 4);

    expect(fn () => Assert::facadeRedactsSensitiveArguments(StockVault::class, 4))
        ->toThrow(AssertionFailedError::class, 'holds it raw');
});

// ---------------------------------------------------------------------------
// Negative controls.
// ---------------------------------------------------------------------------

it('rejects a stock Laravel facade: its __callStatic frame holds every secret raw', function (): void {
    $message = redactionFailure(fn () => expect(StockVault::class)->toRedactSensitiveArguments(methods: 4));

    $frame = Facade::class.'::__callStatic() holds it raw';

    expect($message)
        ->toContain("StockVault::unlock(): argument #1 (\$secret) is #[SensitiveParameter], but frame #1 {$frame}")
        ->toContain("StockVault::unlock(named arguments): argument #1 (\$secret) is #[SensitiveParameter], but frame #1 {$frame}")
        ->toContain("StockVault::encode(): argument #1 (\$bytes) is #[SensitiveParameter], but frame #1 {$frame}")
        ->toContain("StockVault::join(): variadic argument ...\$parts is #[SensitiveParameter], but frame #1 {$frame}")
        ->toContain("StockVault::join(named arguments): variadic argument ...\$parts is #[SensitiveParameter], but frame #1 {$frame}")
        ->toContain("StockVault::fingerprint(): argument #1 (\$key) is #[SensitiveParameter], but frame #1 {$frame}")
        ->not->toContain('yet the facade frame hides it');
});

it('rejects the blunt variant on the harmless-argument check alone', function (): void {
    $message = redactionFailure(fn () => expect(BluntVault::class)->toRedactSensitiveArguments(methods: 4));

    expect($message)
        ->toContain('BluntVault::unlock(): argument #2 ($label) is not #[SensitiveParameter], yet the facade frame hides it')
        ->toContain('BluntVault::join(): argument #1 ($glue) is not #[SensitiveParameter], yet the facade frame hides it')
        // A method that takes no secret at all must keep its arguments visible too.
        ->toContain('BluntVault::label(): argument #1 ($name) is not #[SensitiveParameter], yet the facade frame hides it')
        ->toContain('BluntVault::label(): argument #2 ($width)')
        ->not->toContain('holds it raw');
});

it('rejects a secret that shows raw in a frame below the facade frame', function (): void {
    $message = redactionFailure(fn () => expect(RelayVault::class)->toRedactSensitiveArguments(methods: 4));

    expect($message)
        ->toContain('RelayVault::unlock(): argument #1 ($secret) is #[SensitiveParameter], but frame #1 '.RelayVault::class.'::relay() holds it raw')
        ->toContain('RelayVault::unlock(named arguments): argument #1 ($secret)')
        ->not->toContain('yet the facade frame hides it');
});

it('rejects a trace that captured no arguments', function (): void {
    $message = redactionFailure(fn () => expect(MuteVault::class)->toRedactSensitiveArguments(methods: 4));

    expect($message)->toContain('MuteVault::unlock(): the trace holds no frame of '.MuteVault::class.' with its arguments');
});

it('rejects a facade that never reaches its root', function (): void {
    expect(fn () => expect(SilentVault::class)->toRedactSensitiveArguments(methods: 4))
        ->toThrow(AssertionFailedError::class, 'SilentVault::unlock(): returned without reaching the root');
});

it('rejects a facade that throws before it reaches its root', function (): void {
    expect(fn () => expect(RefusingVault::class)->toRedactSensitiveArguments(methods: 4))
        ->toThrow(AssertionFailedError::class, 'RefusingVault::unlock(): threw LogicException before it reached the root: refused');
});

it('rejects a root method Facade itself shadows, without calling Facade::swap() for real', function (): void {
    $message = redactionFailure(fn () => expect(ShadowVault::class)->toRedactSensitiveArguments(methods: 1));

    // Calling Facade::swap($probeValue) would have returned normally and reported that instead.
    expect($message)
        ->toContain('swap(): '.Facade::class.' declares a public static swap()')
        ->not->toContain('returned without reaching the root')
        ->and(ShadowVault::getFacadeRoot())->toBeInstanceOf(ShadowManager::class);
});

it('rejects a string accessor before probing anything', function (): void {
    expect(fn () => expect(StringKeyed::class)->toRedactSensitiveArguments(methods: 1))
        ->toThrow(AssertionFailedError::class, 'not a class or interface');
});

// ---------------------------------------------------------------------------
// The count pin: it cannot pass over an empty parse.
// ---------------------------------------------------------------------------

it('pins the number of methods that take a secret, static ones included', function (int $methods): void {
    expect(fn () => expect(Vault::class)->toRedactSensitiveArguments(methods: $methods))
        ->toThrow(
            AssertionFailedError::class,
            "methods: {$methods}, but 4 public method(s) of ".VaultManager::class.' take a #[SensitiveParameter] '
            .'argument: unlock(), encode(), join(), fingerprint().',
        );
})->with([3, 5]);

it('refuses a pin of zero or less as a construction error', function (int $methods): void {
    expect(fn () => expect(Vault::class)->toRedactSensitiveArguments(methods: $methods))
        ->toThrow(InvalidArgumentException::class, "needs methods: N of at least 1 (got {$methods})");
})->with([0, -1]);

it('fails a root that marks nothing at all', function (): void {
    expect(fn () => expect(Widgets::class)->toRedactSensitiveArguments(methods: 1))
        ->toThrow(AssertionFailedError::class, 'marks a parameter #[SensitiveParameter], so there is nothing to redact');
});

// ---------------------------------------------------------------------------
// Interface drift: the facade sees only the accessor type's attributes.
// ---------------------------------------------------------------------------

it('reports every attribute the contract and its implementation disagree on', function (): void {
    $message = redactionFailure(fn () => expect(Keyring::class)->toRedactSensitiveArguments(methods: 1));

    $manager = KeyringManager::class;
    $contract = RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Keyring::class;

    expect($message)
        ->toContain("open(), argument #1 (\$pin): {$contract} marks it #[SensitiveParameter] and {$manager} does not")
        ->toContain("seal(), argument #1 (\$token): {$manager} marks it #[SensitiveParameter] and {$contract} does not")
        ->toContain("debug(), argument #1 (\$secret): {$manager} marks it #[SensitiveParameter], but {$contract} does not declare debug()")
        // The trait reads the contract, so the facade frame itself is clean.
        ->not->toContain('holds it raw');
});

it('looks through an installed fake to the implementation it extends', function (): void {
    $fake = Vault::fake();

    expect(Vault::class)->toRedactSensitiveArguments(methods: 4);

    // The fake is back in place, for the facade and for DI, and still records the real values.
    expect(Vault::getFacadeRoot())->toBe($fake)
        ->and(app(VaultManager::class))->toBe($fake);

    Vault::unlock('s3cret', 'front-door');

    expect($fake->unlocked)->toBe([['s3cret', 'front-door']]);
});

it('cannot compare a contract while a fake with nothing behind it is installed', function (): void {
    Locker::fake();

    expect(fn () => expect(Locker::class)->toRedactSensitiveArguments(methods: 1))
        ->toThrow(AssertionFailedError::class, 'and no implementation stands behind it');
});

it('needs the booted application to compare a contract with its implementation', function (): void {
    $app = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);

    try {
        expect(fn () => expect(Locker::class)->toRedactSensitiveArguments(methods: 1))
            ->toThrow(AssertionFailedError::class, 'is an interface and no application is booted');
    } finally {
        Facade::setFacadeApplication($app);
    }
});

it('runs without an application over a class accessor and puts the swapped root back', function (): void {
    $manager = new VaultManager;
    Vault::swap($manager);

    $app = Facade::getFacadeApplication();
    Facade::setFacadeApplication(null);

    try {
        expect(Vault::class)->toRedactSensitiveArguments(methods: 4);

        expect(Vault::getFacadeRoot())->toBe($manager);
    } finally {
        Facade::setFacadeApplication($app);
    }
});

it('fails a contract the container does not bind', function (): void {
    app()->offsetUnset(LockerContract::class);

    expect(fn () => expect(Locker::class)->toRedactSensitiveArguments(methods: 1))
        ->toThrow(AssertionFailedError::class, 'is not bound in the container, so '.Locker::class.' has no root');
});

it('fails a contract whose binding cannot be resolved', function (): void {
    app()->bind(LockerContract::class, fn () => throw new RuntimeException('no locker today'));

    expect(fn () => expect(Locker::class)->toRedactSensitiveArguments(methods: 1))
        ->toThrow(AssertionFailedError::class, 'resolving app('.LockerContract::class.') threw RuntimeException: no locker today');
});

it('fails a contract bound to something that is not an object', function (): void {
    app()->instance(LockerContract::class, 'not-a-locker');

    expect(fn () => expect(Locker::class)->toRedactSensitiveArguments(methods: 1))
        ->toThrow(AssertionFailedError::class, 'resolves to string, not an object');
});

// ---------------------------------------------------------------------------
// Side-effect free.
// ---------------------------------------------------------------------------

it('turns argument capture off for itself and back on afterwards', function (): void {
    // CI's production ini turns capture on; the check must still see the arguments.
    $previous = ini_set('zend.exception_ignore_args', '1');

    try {
        expect(Vault::class)->toRedactSensitiveArguments(methods: 4);
        expect(ini_get('zend.exception_ignore_args'))->toBe('1');

        redactionFailure(fn () => expect(StockVault::class)->toRedactSensitiveArguments(methods: 4));
        expect(ini_get('zend.exception_ignore_args'))->toBe('1');
    } finally {
        ini_set('zend.exception_ignore_args', (string) $previous);
    }
});

it('leaves the facade and the container as it found them', function (): void {
    // Resolved before: the same singleton comes back.
    $locker = app(LockerContract::class);

    expect(Locker::class)->toRedactSensitiveArguments(methods: 1);

    expect(app(LockerContract::class))->toBe($locker)
        ->and(Locker::getFacadeRoot())->toBe($locker);

    // Never resolved before, and the check failed: the real root comes back, never the probe.
    redactionFailure(fn () => expect(StockVault::class)->toRedactSensitiveArguments(methods: 4));

    expect(StockVault::getFacadeRoot())->toBeInstanceOf(VaultManager::class)
        ->and(app(VaultManager::class))->not->toBeInstanceOf(RedactionProbe::class)
        ->and(app(LockerContract::class))->toBeInstanceOf(LockerManager::class);
});
