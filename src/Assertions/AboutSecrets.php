<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Assertions;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * Captures one `artisan about` section and pins that it renders the things it must
 * while leaking none of the secrets it must not.
 *
 * The capture is the whole point. It goes through `Artisan::call('about', ...)` +
 * `Artisan::output()` — never `app(Kernel::class)->output()`, which returns `''` and
 * made the fleet's most credential-heavy secret test vacuous: every "does not leak"
 * assertion passed against an empty string.
 *
 * The order of operations is the institutionalized fix:
 *   1. assert the output is non-empty;
 *   2. assert every `$mustRender` string is present — positive proof the capture worked;
 *   3. only then assert no `$secrets` entry renders.
 *
 * A negative-only test can pass without proving anything ran. `$mustRender` is therefore
 * required and non-empty: an empty list is a construction error, thrown at call time. So is
 * a blank entry in either list — an empty needle is in every output, and a secret that is
 * `null` or `''` (an unset config key or env var) can never be found, so the leak check would
 * pass while checking nothing. An empty `$secrets` *list* is allowed: that is a render-only
 * check, and it says so.
 */
final class AboutSecrets
{
    /**
     * @param  list<string>  $secrets  values that must NOT appear in the section output
     * @param  list<string>  $mustRender  non-empty positive proof the section actually rendered
     */
    public static function assert(string $section, array $secrets, array $mustRender): void
    {
        if ($mustRender === []) {
            throw new InvalidArgumentException(
                'aboutSectionLeaksNoSecrets requires at least one $mustRender string: a negative-only '
                .'secret check can pass vacuously against empty output.',
            );
        }

        self::assertNonEmptyStrings($mustRender, 'mustRender', 'an empty needle is found in every output, so it proves nothing rendered');
        self::assertNonEmptyStrings($secrets, 'secrets', 'a secret that is not set cannot leak, so the check would pass without checking anything — set a real value in the test first');

        Artisan::call('about', ['--only' => $section]);
        $output = Artisan::output();

        Assert::assertNotSame(
            '',
            trim($output),
            "`artisan about --only={$section}` produced no output — the capture is vacuous, so no "
            .'secret assertion below would mean anything.',
        );

        foreach ($mustRender as $needle) {
            Assert::assertStringContainsString(
                $needle,
                $output,
                "The `{$section}` about section did not render '{$needle}'. Either the section is not "
                .'rendering as expected or the capture is stale — fix that before trusting the secret checks.',
            );
        }

        foreach ($secrets as $secret) {
            Assert::assertStringNotContainsString(
                $secret,
                $output,
                "The `{$section}` about section leaked a secret: '{$secret}'.",
            );
        }
    }

    /**
     * Every entry must be a non-blank string. `null` arrives from `config('not.set')`; `''`
     * from an unset env var. Both made a check that looks like it runs, and does not.
     *
     * @param  array<array-key, mixed>  $values
     */
    private static function assertNonEmptyStrings(array $values, string $name, string $why): void
    {
        foreach ($values as $index => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(
                    "aboutSectionLeaksNoSecrets \${$name}[{$index}] is ".(is_string($value) ? "'{$value}'" : get_debug_type($value))
                    .": {$why}.",
                );
            }
        }
    }
}
