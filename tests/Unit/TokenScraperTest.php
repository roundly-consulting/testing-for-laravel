<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\ConfigContract\TokenScraper;

/**
 * Scrape an inline source snippet by writing it to a temp file. Temp files are created
 * at runtime, so Pint never rewrites their double-quoted literals to single quotes —
 * which lets these tests exercise the double-quote decode path directly.
 */
function scrapeSource(string $source, array $extraReadPrefixes = [], array $sectionVariables = []): array
{
    $file = (string) tempnam(sys_get_temp_dir(), 'scrape').'.php';
    file_put_contents($file, "<?php\n\n".$source);

    try {
        $scraped = (new TokenScraper('shop', $extraReadPrefixes))->scrape($file, $sectionVariables);

        return [
            'reads' => $scraped->reads,
            'interpolations' => $scraped->interpolations,
            'dynamicSections' => $scraped->dynamicSections,
        ];
    } finally {
        @unlink($file);
    }
}

it('reads a single-quoted config key', function (): void {
    expect(scrapeSource("config('shop.a');")['reads'])->toBe(['shop.a']);
});

it('reads a double-quoted config key', function (): void {
    expect(scrapeSource('config("shop.b");')['reads'])->toBe(['shop.b']);
});

it('reads a key through the Config facade', function (): void {
    expect(scrapeSource("Config::get('shop.c');")['reads'])->toBe(['shop.c']);
});

it('ignores a config key outside the prefix', function (): void {
    expect(scrapeSource("config('app.name');")['reads'])->toBe([]);
});

it('ignores a config property access that is not a call', function (): void {
    expect(scrapeSource('$this->config;')['reads'])->toBe([]);
});

it('flags a concatenated key under the prefix', function (): void {
    $result = scrapeSource("config('shop.'.\$name);");

    expect($result['reads'])->toBe([])
        ->and($result['interpolations'])->toHaveCount(1);
});

it('flags an interpolated key under the prefix', function (): void {
    $result = scrapeSource('config("shop.{$name}");');

    expect($result['reads'])->toBe([])
        ->and($result['interpolations'])->toHaveCount(1);
});

it('does not flag a fully dynamic key with no prefix literal', function (): void {
    $result = scrapeSource('config($key);');

    expect($result['reads'])->toBe([])
        ->and($result['interpolations'])->toBe([]);
});

/**
 * The driver-keyed shape — Laravel's own `database.connections.<name>`. The driver segment is
 * a runtime value; the leaf after it is a literal, and that is what gets proven.
 */
it('scrapes a driver-keyed read into a pattern that pins the leaf', function (): void {
    $result = scrapeSource('config("shop.providers.{$key}.url");');

    expect($result['reads'])->toBe(['shop.providers.*.url'])
        ->and($result['interpolations'])->toBe([]);
});

it('scrapes a driver hole filled by a method call', function (): void {
    $result = scrapeSource('config("shop.providers.{$this->key()}.webhook_secret");');

    expect($result['reads'])->toBe(['shop.providers.*.webhook_secret']);
});

it('scrapes a concatenated driver-keyed read', function (): void {
    $result = scrapeSource("config('shop.providers.'.\$key.'.url');");

    expect($result['reads'])->toBe(['shop.providers.*.url'])
        ->and($result['interpolations'])->toBe([]);
});

/**
 * A read that stops at the hole is a wholesale section read. It degrades to the literal
 * parent — which the forward direction can still check — and is recorded as a dynamic
 * section so a reverse failure underneath can explain itself. It proves no leaf.
 */
it('degrades a trailing driver hole to the literal parent', function (): void {
    $result = scrapeSource('config("shop.providers.{$this->key()}", []);');

    expect($result['reads'])->toBe(['shop.providers'])
        ->and($result['interpolations'])->toBe([])
        ->and($result['dynamicSections'])->toHaveCount(1);
});

/**
 * A package that parks its config section on a property and indexes it is as ordinary as one
 * that uses a local. `$this->config` is three tokens, and the mapping used to be matched
 * against a lone `T_VARIABLE`, so it was never consulted: every leaf under it scraped as
 * unread, which is the reverse check inventing a dead key.
 */
it('maps offsets on a section held in a property', function (): void {
    $result = scrapeSource(
        '$x = $this->config[\'public\'][\'enabled\'];',
        sectionVariables: ['$this->config' => 'shop.media'],
    );

    expect($result['reads'])->toBe(['shop.media.public.enabled']);
});

/**
 * Anchored on `$this`: another object's property is not this class's section, and attributing
 * it here would invent a read — which blinds the reverse check on a key that really is dead.
 */
it('does not map offsets on another object property of the same name', function (): void {
    $result = scrapeSource(
        '$x = $other->config[\'public\'];',
        sectionVariables: ['$this->config' => 'shop.media'],
    );

    expect($result['reads'])->toBe([]);
});

/**
 * The guard rail: a hole that bleeds into its segment cannot be reasoned about segment-wise,
 * and a derived pattern would be a guess. Guessing is how a check starts lying.
 */
it('refuses a hole that does not fill a whole segment', function (): void {
    $result = scrapeSource('config("shop.providers.{$key}url");');

    expect($result['reads'])->toBe([])
        ->and($result['interpolations'])->toHaveCount(1);
});

it('refuses a key that is not built from literals and holes', function (): void {
    $result = scrapeSource("config('shop.'.\$this->keyFor('x'));");

    expect($result['reads'])->toBe([])
        ->and($result['interpolations'])->toHaveCount(1);
});

it('counts a literal under an extra read prefix anywhere', function (): void {
    expect(scrapeSource("Model::for('shop.model');", ['shop.'])['reads'])->toBe(['shop.model']);
});

it('reads array offsets on a section variable', function (): void {
    $reads = scrapeSource('$id = $rp[\'id\'];', [], ['$rp' => 'shop.rp'])['reads'];

    expect($reads)->toBe(['shop.rp.id']);
});

it('ignores a section variable used without a string offset', function (): void {
    expect(scrapeSource('$x = $rp;', [], ['$rp' => 'shop.rp'])['reads'])->toBe([]);
});

/**
 * Injected `Illuminate\Contracts\Config\Repository` reads — the shape that scraped as
 * *zero reads* in a file with six of them (`http-client-rate-limits`), silently reporting
 * live, tested keys as unread and inviting an `allowUnread` that asserts a falsehood.
 */
it('reads a key through an injected config repository property', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(): void
            {
                $this->config->get('shop.cache_store');
            }
        }
        PHP)['reads'])->toBe(['shop.cache_store']);
});

it('reads a key through an injected config repository parameter', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        function boot(Repository $config): void
        {
            $config->get('shop.deferrer');
        }
        PHP)['reads'])->toBe(['shop.deferrer']);
});

it('reads a key through an aliased config repository import', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository as ConfigRepository;

        final class Manager
        {
            public function __construct(private readonly ConfigRepository $config) {}

            public function run(): void
            {
                $this->config->get('shop.aliased');
            }
        }
        PHP)['reads'])->toBe(['shop.aliased']);
});

it('reads a key through the concrete config repository class', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(): void
            {
                $this->config->get('shop.concrete');
            }
        }
        PHP)['reads'])->toBe(['shop.concrete']);
});

it('reads a key through the repository typed-getter family', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(): void
            {
                $this->config->string('shop.a');
                $this->config->integer('shop.b');
                $this->config->boolean('shop.c');
                $this->config->array('shop.d');
            }
        }
        PHP)['reads'])->toBe(['shop.a', 'shop.b', 'shop.c', 'shop.d']);
});

/**
 * The boundary that makes the binding type-driven rather than name-driven. A cache
 * repository is a different type, so a lookup under a key that merely shares the prefix is
 * not a config read. Counting it would *invent* a read — and an invented read blinds the
 * reverse check on a key that really is dead, which is the failure this whole contract
 * exists to catch.
 */
it('ignores a get on a repository that is not the config repository', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Cache\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $cache) {}

            public function run(): void
            {
                $this->cache->get('shop.cached_thing');
            }
        }
        PHP)['reads'])->toBe([]);
});

it('ignores a config-repository property read on another object', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(Other $other): void
            {
                $other->config->get('shop.not_ours');
            }
        }
        PHP)['reads'])->toBe([]);
});

it('does not count a repository set as a read', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(): void
            {
                $this->config->set('shop.written_only', 1);
            }
        }
        PHP)['reads'])->toBe([]);
});

/**
 * A driver-keyed read through an injected repository is checkable for the same reason a bare
 * `config()` one is: the leaf (`rate`) is a literal in the source, and only the driver is a
 * runtime value. It used to be flagged wholesale as an uncheckable interpolation.
 */
it('proves the leaf of a driver-keyed key read through an injected repository', function (): void {
    $result = scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(string $name): void
            {
                $this->config->get("shop.{$name}.rate");
            }
        }
        PHP);

    expect($result['reads'])->toBe(['shop.*.rate'])
        ->and($result['interpolations'])->toBe([]);
});

/**
 * #6: nested offsets.
 *
 * `sectionVariables` mapped exactly one offset level, so a package that indexed two levels
 * into a wholesale section registered only the parent path — and a parent read deliberately
 * proves nothing per-leaf, so every leaf scraped as unread. Indistinguishable from a real
 * dead key, and the tempting fix was an `allowUnread` that asserts a falsehood.
 */
it('reads a nested array offset as a dotted path', function (): void {
    expect(scrapeSource(
        "\$rl['public']['enabled'];",
        sectionVariables: ['$rl' => 'shop.rate_limiters'],
    )['reads'])->toBe(['shop.rate_limiters.public.enabled']);
});

it('reads offsets nested three levels deep', function (): void {
    expect(scrapeSource(
        "\$rl['a']['b']['c'];",
        sectionVariables: ['$rl' => 'shop.rate_limiters'],
    )['reads'])->toBe(['shop.rate_limiters.a.b.c']);
});

it('still reads a single array offset', function (): void {
    expect(scrapeSource(
        "\$rp['id'];",
        sectionVariables: ['$rp' => 'shop.rp'],
    )['reads'])->toBe(['shop.rp.id']);
});

/**
 * A dynamic offset stops the walk instead of guessing: the read degrades to the parent path,
 * so the leaves below it stay unproven and fail *visibly* rather than passing quietly.
 */
it('stops at a non-literal offset rather than guessing', function (): void {
    expect(scrapeSource(
        "\$rl['public'][\$name];",
        sectionVariables: ['$rl' => 'shop.rate_limiters'],
    )['reads'])->toBe(['shop.rate_limiters.public']);
});

// ---------------------------------------------------------------------------
// Spellings of the same read that used to be invisible.
// ---------------------------------------------------------------------------

it('reads the fully-qualified helper and facade', function (): void {
    expect(scrapeSource("\\config('shop.a'); \\Illuminate\\Support\\Facades\\Config::get('shop.b'); \\Config::integer('shop.c');")['reads'])
        ->toBe(['shop.a', 'shop.b', 'shop.c']);
});

it('reads through an import alias of the facade, and not through an unrelated class', function (): void {
    $source = "use Illuminate\\Support\\Facades\\Config as Settings;\nuse App\\Cache as Store;\nSettings::get('shop.a'); Store::get('shop.b');";

    expect(scrapeSource($source)['reads'])->toBe(['shop.a']);
});

it('does not read a method, a declaration or an instantiation named config', function (): void {
    expect(scrapeSource("\$x->config('shop.a'); Foo::config('shop.b'); new config('shop.c'); function config(\$k) {}")['reads'])->toBe([]);
});

it('reads through the repository returned by an expression', function (string $call): void {
    expect(scrapeSource("use Illuminate\\Contracts\\Config\\Repository;\n{$call}")['reads'])->toBe(['shop.k']);
})->with([
    "config()->string('shop.k');",
    "\\config()?->get('shop.k');",
    "app('config')->get('shop.k');",
    "\\app(Repository::class)->get('shop.k');",
    "resolve('config')->get('shop.k');",
    "app()->make('config')->get('shop.k');",
    "App::make('config')->get('shop.k');",
    "\$this->app['config']->get('shop.k');",
    "app()['config']->get('shop.k');",
]);

it('does not read through an expression that yields some other service', function (string $call): void {
    expect(scrapeSource("use Illuminate\\Cache\\Repository;\n{$call}")['reads'])->toBe([]);
})->with([
    "config('other.x')->get('shop.k');",
    "app('cache')->get('shop.k');",
    "app(Repository::class)->get('shop.k');",
    "\$this->app['cache']->get('shop.k');",
    "make('config')->get('shop.k');",
    "\$x->app('config')->get('shop.k');",
    "(\$factory)->get('shop.k');",
    "\$items[0]->get('shop.k');",
]);

it('treats an array handed to the helper as a write, and to a read method as getMany', function (): void {
    expect(scrapeSource("config(['shop.a' => true]); config(array('shop.b' => 1));")['reads'])->toBe([])
        ->and(scrapeSource("config()->get(['shop.c', 'shop.d' => 'fallback', 'other.e']);")['reads'])->toBe(['shop.c', 'shop.d'])
        ->and(scrapeSource("config(['shop.a' => true]);")['interpolations'])->toBe([]);
});

it('reads a Blade view through its PHP-bearing constructs only', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'view').'.blade.php';
    file_put_contents($file, "{{-- config('shop.comment') --}}\n<p>It's {{ config('shop.a') }}</p>\n@@if(config('shop.escaped_directive'))\n@if(config('shop.b'))@endif");

    try {
        expect((new TokenScraper('shop'))->scrape($file)->reads)->toBe(['shop.a', 'shop.b']);
    } finally {
        @unlink($file);
    }
});
