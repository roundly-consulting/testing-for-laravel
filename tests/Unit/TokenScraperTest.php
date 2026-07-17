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

        return ['reads' => $scraped->reads, 'interpolations' => $scraped->interpolations];
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

it('flags an interpolated key read through an injected repository', function (): void {
    expect(scrapeSource(<<<'PHP'
        use Illuminate\Contracts\Config\Repository;

        final class Manager
        {
            public function __construct(private readonly Repository $config) {}

            public function run(string $name): void
            {
                $this->config->get("shop.{$name}.rate");
            }
        }
        PHP)['interpolations'])->not->toBe([]);
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
