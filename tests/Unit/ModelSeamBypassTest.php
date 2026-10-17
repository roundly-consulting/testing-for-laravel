<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\ModelSeam;
use RoundlyConsulting\Testing\Arch\PhpSource;
use RoundlyConsulting\Testing\Arch\QueryForwarding;
use RoundlyConsulting\Testing\Arch\SeamModels;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Statics\Models\Gadget as StaticsGadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Cog;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Gadget as DiscoveredGadget;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Models\Sprocket as DiscoveredSprocket;

// ---------------------------------------------------------------------------
// modelsResolveThroughSeam, halves 3 and 4: outside the seam nothing names a swappable
// model's packaged class to relate it (comments 1.1.0's `CommentMention::comment()`) or to
// query it (comments 1.1.0's `AssertsComments`). Green first, then proves-it-bites.
// ---------------------------------------------------------------------------

$bypassFixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/SeamBypass/'.$path;

/**
 * The failure message of a seam assertion that must fail.
 */
function seamFailure(Closure $assertion): string
{
    try {
        $assertion();
    } catch (AssertionFailedError $failure) {
        return $failure->getMessage();
    }

    throw new RuntimeException('The seam assertion passed, but it had to fail.');
}

it('accepts a package that relates and queries swappable models only through the seam', function () use ($bypassFixture): void {
    // Docblocks, comments and strings naming Gadget::query() / belongsTo(Gadget::class),
    // `instanceof Gadget`, `is_a(.., Gadget::class)`, `static::class`, a non-swappable model
    // named freely, the model's own static helper, Model's hook statics, the seam itself
    // naming the packaged class, and a seam holding its key and default in constants.
    ModelSeam::assert($bypassFixture('Green'), 'Support', ['arch.gadget_model', 'arch.sprocket_model']);

    expect(true)->toBeTrue();
});

it('rejects a relation naming a swappable model by its packaged class', function () use ($bypassFixture): void {
    $message = seamFailure(fn () => ModelSeam::assert($bypassFixture('Relations')));

    expect($message)
        ->toContain('These relations name a swappable model by its packaged class')
        ->toMatch('#Models/GadgetPart\.php:\d+ belongsTo\(Gadget::class\)#')
        // the through model, a named `related:` argument and a nullsafe call
        ->toMatch('#Models/GadgetPart\.php:\d+ hasManyThrough\(Gadget::class\)#')
        ->toMatch('#Models/GadgetPart\.php:\d+ belongsToMany\(Gadget::class\)#')
        // `self::class` inside the swappable model is its packaged class
        ->toMatch('#Models/Gadget\.php:\d+ hasMany\(Gadget::class\)#')
        // `parent::class` in a packaged subclass, beside an interpolated argument
        ->toMatch('#Models/SpecialGadget\.php:\d+ belongsTo\(Gadget::class\)#')
        // fully qualified, and through an aliased import, in a host-model trait
        ->toMatch('#Concerns/HasGadgets\.php:\d+ morphMany\(Gadget::class\)#')
        ->toMatch('#Concerns/HasGadgets\.php:\d+ morphToMany\(Gadget::class\)#')
        // `hasManyThrough(Part::class, …)` names the non-swappable Part: not reported
        ->not->toContain('Part::class');
});

it('rejects a static call that starts a query on a swappable model', function () use ($bypassFixture): void {
    $message = seamFailure(fn () => ModelSeam::assert($bypassFixture('Statics')));

    expect($message)
        ->toContain('These static calls start a query on a swappable model')
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::query\(\)#')
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::where\(\)#')
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::withTrashed\(\)#')
        // a local scope, Model's own entry point, an aliased import, a forwarded create
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::active\(\)#')
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::all\(\)#')
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::count\(\)#')
        ->toMatch('#Testing/GadgetAssertions\.php:\d+ Gadget::firstOrCreate\(\)#')
        // the model's own static helper and Model's hook registration build no query
        ->not->toContain('forSerial')
        ->not->toContain('creating')
        // the seam may name the packaged class: that is where the default lives
        ->not->toContain('Support/');
});

it('reads forwarding from the builders when the swappable model cannot be loaded', function () use ($bypassFixture): void {
    $message = seamFailure(fn () => ModelSeam::assert($bypassFixture('Unloadable')));

    expect($message)
        ->toMatch('#Actions/ListGadgets\.php:\d+ Gadget::where\(\)#')
        ->toMatch('#Actions/ListGadgets\.php:\d+ Gadget::onlyTrashed\(\)#')
        ->not->toContain('creating')
        // an unknown name errs toward the false negative, never the unappealable red
        ->not->toContain('somethingUnknown');
});

it('fails a declared key whose seam does not name the default it resolves', function () use ($bypassFixture): void {
    // The seam takes the default as an argument, so which model `arch.gadget_model` swaps
    // cannot be read from the code — halves 3 and 4 would be blind to it.
    expect(fn () => ModelSeam::assert($bypassFixture('UnresolvedKey'), 'Support', ['arch.gadget_model']))
        ->toThrow(AssertionFailedError::class, "Support/GadgetModel.php: 'arch.gadget_model'");
});

it('does not hold an undeclared, inferred key to that rule', function () use ($bypassFixture): void {
    // Pinned on purpose: key-shape inference also matches keys that swap nothing the package
    // ships (lifecycle reads `auth.providers.users.model` in its seam), and this case has no
    // `->ignoring()` escape. Declaring the key is what makes the rule apply.
    ModelSeam::assert($bypassFixture('UnresolvedKey'));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Which models are swappable: read from the package's own seam.
// ---------------------------------------------------------------------------

it('discovers exactly the models a seam method names beside a config key', function (): void {
    $srcDir = dirname(__DIR__).'/Fixtures/Arch/SeamDiscovery';
    $models = SeamModels::discover(PhpSource::allIn($srcDir), $srcDir, 'Support');
    $shapes = 'RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamDiscovery\Support\Shapes';

    // Not: an instance method, a method taking a parameter, a method naming no key, a non-string
    // return, an interface, Eloquent's own base, nor a seam-shaped method outside the seam dir.
    expect($models->models())->toBe([DiscoveredGadget::class, DiscoveredSprocket::class])
        ->and($models->seamsFor(DiscoveredGadget::class))->toBe([$shapes.'::gadget'])
        ->and($models->resolvedBy($shapes.'::sprocket'))->toBe([DiscoveredSprocket::class])
        ->and($models->resolvedBy(strtoupper($shapes).'::SPROCKET'))->toBe([DiscoveredSprocket::class])
        ->and($models->resolvedBy($shapes.'::noKey'))->toBe([])
        ->and($models->isSwappable(strtolower(DiscoveredGadget::class)))->toBeTrue()
        ->and($models->isSwappable(Cog::class))->toBeFalse()
        ->and($models->seamsFor(Cog::class))->toBe([])
        ->and($models->readsKey('arch.gadget_model'))->toBeTrue()
        ->and($models->readsKey('arch.cog_model'))->toBeFalse();
});

it('reads a seam that keeps its key and default in class constants', function (): void {
    $srcDir = dirname(__DIR__).'/Fixtures/Arch/SeamBypass/Green';
    $models = SeamModels::discover(PhpSource::allIn($srcDir), $srcDir, 'Support');

    expect($models->resolvedBy('RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Support\Catalog::sprocket'))
        ->toBe(['RoundlyConsulting\Testing\Tests\Fixtures\Arch\SeamBypass\Green\Models\Sprocket'])
        ->and($models->readsKey('arch.sprocket_model'))->toBeTrue();
});

it('finds no swappable model where no seam directory exists', function (): void {
    $srcDir = dirname(__DIR__).'/Fixtures/Arch/SeamDiscovery';
    $models = SeamModels::discover(PhpSource::allIn($srcDir), $srcDir, 'Nowhere');

    expect($models->models())->toBe([]);
});

// ---------------------------------------------------------------------------
// What forwards to a query, and how source is read.
// ---------------------------------------------------------------------------

it('tells a forwarded query from a static the model declares', function (): void {
    // Loadable: anything the class does not declare forwards — scopes and macros included.
    expect(QueryForwarding::startsQuery(StaticsGadget::class, 'query'))->toBeTrue()
        ->and(QueryForwarding::startsQuery(StaticsGadget::class, 'where'))->toBeTrue()
        ->and(QueryForwarding::startsQuery(StaticsGadget::class, 'active'))->toBeTrue()
        ->and(QueryForwarding::startsQuery(StaticsGadget::class, 'forSerial'))->toBeFalse()
        ->and(QueryForwarding::startsQuery(StaticsGadget::class, 'creating'))->toBeFalse()
        // Not loadable: the builders' methods and the soft-delete macros, minus Model's own.
        ->and(QueryForwarding::startsQuery('Missing\Gadget', 'firstOrCreate'))->toBeTrue()
        ->and(QueryForwarding::startsQuery('Missing\Gadget', 'withTrashed'))->toBeTrue()
        ->and(QueryForwarding::startsQuery('Missing\Gadget', 'all'))->toBeTrue()
        ->and(QueryForwarding::startsQuery('Missing\Gadget', 'observe'))->toBeFalse()
        ->and(QueryForwarding::startsQuery('Missing\Gadget', 'madeUp'))->toBeFalse();
});

it('resolves names through grouped, aliased and function imports', function (): void {
    $source = PhpSource::parse(<<<'PHP'
        <?php

        namespace App\Things;

        use Vendor\Pkg\{Alpha, Beta as Bee};
        use function Vendor\helper;
        use const Vendor\LIMIT;
        use Vendor\Models;

        abstract class Thing extends Models\Base
        {
            use SomeTrait;

            public function run(): void
            {
                $closure = function () use ($x) {};
            }
        }
        PHP);

    expect($source->class)->toBe('App\Things\Thing')
        ->and($source->kind)->toBe(T_CLASS)
        ->and($source->abstract)->toBeTrue()
        ->and($source->parent)->toBe('Vendor\Models\Base')
        ->and($source->resolve('Alpha'))->toBe('Vendor\Pkg\Alpha')
        ->and($source->resolve('Bee'))->toBe('Vendor\Pkg\Beta')
        ->and($source->resolve('Bee\Inner'))->toBe('Vendor\Pkg\Beta\Inner')
        ->and($source->resolve('helper'))->toBe('App\Things\helper')
        ->and($source->resolve('SomeTrait'))->toBe('App\Things\SomeTrait')
        ->and($source->resolve('\Root\Name'))->toBe('Root\Name')
        ->and($source->resolve('namespace\Local'))->toBe('App\Things\Local');
});

it('reads a file with no namespace and no class', function (): void {
    $source = PhpSource::parse("<?php\n\nfunction helper(): void {}\n");

    expect($source->class)->toBeNull()
        ->and($source->parent)->toBeNull()
        ->and($source->abstract)->toBeFalse()
        ->and($source->resolve('Thing'))->toBe('Thing');
});

it('walks inheritance through source first, then reflection', function (): void {
    $parents = ['App\Child' => 'App\Middle', 'App\Middle' => StaticsGadget::class];

    expect(PhpSource::inherits('App\Child', StaticsGadget::class, $parents))->toBeTrue()
        // the chain leaves the set at a loadable class: reflection answers
        ->and(PhpSource::inherits('App\Child', Model::class, $parents))->toBeTrue()
        ->and(PhpSource::inherits('App\Loop', 'App\Other', ['App\Loop' => 'App\Loop']))->toBeFalse()
        ->and(PhpSource::inherits('App\Unknown', Model::class, []))->toBeFalse();
});

it('finds the closing bracket, and the last token when there is none', function (): void {
    $tokens = PhpSource::parse('<?php f(a, [b], "{$c}"); g();')->tokens;
    $unbalanced = PhpSource::parse('<?php f(a, (b')->tokens;

    // `"{$c}"` opens with T_CURLY_OPEN and closes with a plain `}`: balanced, not a stray.
    expect($tokens[1][1])->toBe('(')
        ->and(PhpSource::closing($tokens, 1))->toBe(13)
        ->and($tokens[13][1])->toBe(')')
        ->and(PhpSource::closing($unbalanced, 1))->toBe(count($unbalanced) - 1);
});

it('treats a keyword-spelled method name as an identifier', function (): void {
    // `function class()` is T_CLASS and `X::list()` is T_LIST — still method names.
    expect(PhpSource::isIdentifier([T_CLASS, 'class', 1]))->toBeTrue()
        ->and(PhpSource::isIdentifier([T_STRING, 'query', 1]))->toBeTrue()
        ->and(PhpSource::isIdentifier([T_VARIABLE, '$model', 1]))->toBeFalse()
        ->and(PhpSource::isIdentifier([null, '(', 1]))->toBeFalse()
        ->and(PhpSource::isIdentifier(null))->toBeFalse();
});
