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
