<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Testing\Arch\VendorNamespaces;
use RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Leaky\PsrClient;

$fixture = fn (string $path): string => dirname(__DIR__).'/Fixtures/Arch/VendorNamespace/'.$path;

/**
 * The failure message of a scan that must fail.
 */
function vendorNamespaceFailure(Closure $scan): string
{
    try {
        $scan();
    } catch (AssertionFailedError $e) {
        return $e->getMessage();
    }

    throw new LogicException('The scan passed, but it had to fail.');
}

// ---------------------------------------------------------------------------
// The negative control: the gap in Pest's arch layer this preset closes.
// ---------------------------------------------------------------------------

it('catches a sibling package under a vendor prefix that Pest\'s bare toUse() lets through', function () use ($fixture): void {
    // Green, although PsrClient imports GuzzleHttp\Psr7\Utils: guzzlehttp/psr7 has its own PSR-4
    // root, so Pest never expands `GuzzleHttp` to it.
    expect('RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Leaky')->not->toUse('GuzzleHttp');

    // The same layer does see the fixture once the sibling root is named, so the green above is
    // the gap, not an empty scan.
    expect(function (): void {
        expect('RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Leaky')->not->toUse('GuzzleHttp\Psr7');
    })->toThrow(AssertionFailedError::class);

    // The token scan goes red on the bare prefix.
    expect(fn () => VendorNamespaces::assert(['GuzzleHttp'], $fixture('Leaky')))
        ->toThrow(AssertionFailedError::class, 'PsrClient.php:7 GuzzleHttp\Psr7\Utils');
});

// ---------------------------------------------------------------------------
// Every way code names a namespace.
// ---------------------------------------------------------------------------

it('resolves imports, aliases, groups, function and const imports, qualified and fully-qualified names', function () use ($fixture): void {
    $message = vendorNamespaceFailure(fn () => VendorNamespaces::assert(['Acme', 'App'], $fixture('Shapes')));

    expect($message)
        ->toContain("These files reference a banned namespace (Acme\\, App\\):\n")
        // `use Acme;` names the prefix itself; then an aliased import and a group import.
        ->toContain("- Everything.php:7 Acme\n")
        ->toContain("- Everything.php:8 Acme\\Billing\\Invoice\n")
        ->toContain("- Everything.php:9 Acme\\Shipping\\Zone\n")
        ->toContain("- Everything.php:9 Acme\\Tax\\Rate\n")
        // `use function` and `use const`.
        ->toContain("- Everything.php:11 Acme\\Helpers\\money\n")
        ->toContain("- Everything.php:12 Acme\\Limits\\MAX\n")
        // Fully-qualified: an attribute, extends, implements (a host name), catch, a static call.
        ->toContain("- Everything.php:19 Acme\\Attributes\\Audited\n")
        ->toContain("- Everything.php:20 Acme\\Base\\Model\n")
        ->toContain("- Everything.php:20 App\\Contracts\\Tenant\n")
        ->toContain("- Everything.php:26 Acme\\Errors\\Failed\n")
        ->toContain("- Everything.php:27 App\\Models\\User\n")
        // Qualified, through an alias and through a namespace import.
        ->toContain("- Everything.php:25 Acme\\Billing\\Invoice\\Line\n")
        ->toContain("- Everything.php:25 Acme\\Report\\Pdf\n")
        // A file that declares no class.
        ->toContain("- helpers.php:10 Acme\\Money\n")
        // The package's own import is not under a banned prefix.
        ->not->toContain('Support\Helper');
});

it('reads braced namespaces and the global namespace', function () use ($fixture): void {
    $message = vendorNamespaceFailure(fn () => VendorNamespaces::assert(['Acme', 'App'], $fixture('Braced')));

    expect($message)
        ->toContain('Braced.php:4 Acme\Gateway')
        ->toContain('Braced.php:16 App\Http\Kernel');
});

it('ignores comments, docblocks, strings, member names and names relative to the file\'s own namespace', function () use ($fixture): void {
    VendorNamespaces::assert(['GuzzleHttp', 'App', 'Acme'], $fixture('Green'));

    expect(true)->toBeTrue();
});

it('matches prefixes case-insensitively and tolerates surrounding backslashes', function (string $prefix) use ($fixture): void {
    expect(fn () => VendorNamespaces::assert([$prefix], $fixture('Leaky')))
        ->toThrow(AssertionFailedError::class, 'PsrClient.php:7 GuzzleHttp\Psr7\Utils');
})->with(['guzzlehttp', '\GuzzleHttp\\', 'GuzzleHttp\Psr7']);

it('does not match a namespace that merely starts with the same letters', function () use ($fixture): void {
    // `Guzzle` is not `GuzzleHttp\`: a prefix ends at a namespace separator.
    VendorNamespaces::assert(['Guzzle', 'GuzzleHttp\Psr'], $fixture('Leaky'));

    expect(true)->toBeTrue();
});

// ---------------------------------------------------------------------------
// It cannot pass over nothing.
// ---------------------------------------------------------------------------

it('fails when the source directory does not exist', function (): void {
    expect(fn () => VendorNamespaces::assert(['GuzzleHttp'], '/nope/not/here'))
        ->toThrow(AssertionFailedError::class, 'Source directory does not exist: /nope/not/here');
});

it('fails a directory that holds no PHP file', function () use ($fixture): void {
    expect(fn () => VendorNamespaces::assert(['GuzzleHttp'], $fixture('Empty')))
        ->toThrow(AssertionFailedError::class, 'so the namespace ban would pass over nothing');
});

it('refuses an empty prefix list or a blank prefix', function (array $prefixes, string $message) use ($fixture): void {
    expect(fn () => VendorNamespaces::assert($prefixes, $fixture('Leaky')))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no prefix' => [[], 'needs at least one namespace prefix'],
    'empty string' => [[''], 'got a blank prefix'],
    'only backslashes' => [['\\\\'], 'got a blank prefix'],
]);

it('fails a prefix that covers the scanned code\'s own namespace', function () use ($fixture): void {
    expect(fn () => VendorNamespaces::assert(['RoundlyConsulting\Testing\Tests\Fixtures\Arch'], $fixture('Green')))
        ->toThrow(AssertionFailedError::class, 'covers RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Green (Clean.php)');
});

// ---------------------------------------------------------------------------
// Exemptions.
// ---------------------------------------------------------------------------

it('exempts a class by name or by namespace', function (string $exemption) use ($fixture): void {
    VendorNamespaces::assert(['GuzzleHttp'], $fixture('Leaky'), [$exemption]);

    expect(true)->toBeTrue();
})->with([
    'class' => PsrClient::class,
    'namespace' => 'RoundlyConsulting\Testing\Tests\Fixtures\Arch\VendorNamespace\Leaky',
]);

it('fails an exemption that matches no class in the scanned directory', function () use ($fixture): void {
    expect(fn () => VendorNamespaces::assert(['GuzzleHttp'], $fixture('Green'), [PsrClient::class]))
        ->toThrow(AssertionFailedError::class, 'These noVendorNamespace exemptions match no class declared under');
});
