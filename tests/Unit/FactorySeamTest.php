<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\FactorySeam;

// ---------------------------------------------------------------------------
// factoriesResolveThroughSeam — a factory of a swappable model overrides modelName() to
// return the seam, so a host's swapped model gets its own rows. Green, then proves-it-bites,
// then the inputs that would let it pass over nothing.
// ---------------------------------------------------------------------------

$factoryFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/FactorySeam/'.$path;

it('accepts factories that build swappable models through the seam', function () use ($factoryFixture): void {
    // GadgetFactory returns GadgetModel::class(); SprocketFactory declares no $model and is read
    // through the seam it returns; WidgetGadgetFactory extends an in-directory abstract base;
    // PartFactory builds a model nobody swaps; NotAFactory is not a factory.
    FactorySeam::assert($factoryFixture('Green'), $factoryFixture('Src'));

    expect(true)->toBeTrue();
});

it('rejects a factory of a swappable model with no modelName() override', function () use ($factoryFixture): void {
    // The fix spelled out in the factory's docblock does not count: prose is not code.
    expect(fn () => FactorySeam::assert($factoryFixture('Missing'), $factoryFixture('Src')))
        ->toThrow(
            AssertionFailedError::class,
            'GadgetFactory.php: builds Gadget but does not return its seam from modelName() — return GadgetModel::class()',
        );
});

it('rejects a modelName() that returns the seam of another model', function () use ($factoryFixture): void {
    expect(fn () => FactorySeam::assert($factoryFixture('WrongSeam'), $factoryFixture('Src')))
        ->toThrow(
            AssertionFailedError::class,
            'GadgetFactory.php: builds Gadget but modelName() returns Catalog::sprocket() — return GadgetModel::class()',
        );
});

it('rejects a modelName() that returns anything but a seam call', function () use ($factoryFixture): void {
    try {
        FactorySeam::assert($factoryFixture('NotTheSeam'), $factoryFixture('Src'));
    } catch (AssertionFailedError $failure) {
        // `return $this->model;` and `return Sprocket::class;` both build the packaged class.
        expect($failure->getMessage())
            ->toContain('GadgetFactory.php: builds Gadget but does not return its seam')
            ->toContain('SprocketFactory.php: builds Sprocket but does not return its seam from modelName() — return Catalog::sprocket()');

        return;
    }

    $this->fail('A modelName() returning the packaged class passed.');
});

it('rejects a factory whose model cannot be read rather than skipping it', function () use ($factoryFixture): void {
    expect(fn () => FactorySeam::assert($factoryFixture('Unreadable'), $factoryFixture('Src')))
        ->toThrow(AssertionFailedError::class, 'MysteryFactory.php: cannot tell which model it builds');
});

it('fails when no factory builds a swappable model', function () use ($factoryFixture): void {
    expect(fn () => FactorySeam::assert($factoryFixture('NoSwappable'), $factoryFixture('Src')))
        ->toThrow(AssertionFailedError::class, 'None of the 1 factories');
});

it('fails on a directory with no factory class', function () use ($factoryFixture): void {
    expect(fn () => FactorySeam::assert($factoryFixture('NoFactories'), $factoryFixture('Src')))
        ->toThrow(AssertionFailedError::class, 'No Eloquent factory class was found');

    expect(fn () => FactorySeam::assert($factoryFixture('Empty'), $factoryFixture('Src')))
        ->toThrow(AssertionFailedError::class, 'No Eloquent factory class was found');
});

it('fails when the seam names no swappable model', function () use ($factoryFixture): void {
    expect(fn () => FactorySeam::assert($factoryFixture('Green'), $factoryFixture('Src'), 'Nowhere'))
        ->toThrow(AssertionFailedError::class, 'No swappable model was found');
});

it('fails on a missing directory', function () use ($factoryFixture): void {
    expect(fn () => FactorySeam::assert($factoryFixture('does-not-exist'), $factoryFixture('Src')))
        ->toThrow(AssertionFailedError::class, 'Factories directory does not exist');

    expect(fn () => FactorySeam::assert($factoryFixture('Green'), $factoryFixture('does-not-exist')))
        ->toThrow(AssertionFailedError::class, 'Source directory does not exist');
});
