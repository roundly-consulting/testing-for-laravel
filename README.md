<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/testing-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=testing-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/testing-for-laravel/main/art/hero.png" alt="Testing for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/testing-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/testing-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/testing-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/testing-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/testing-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/testing-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=testing-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Testing for Laravel

**The test suite that can't lie to you.** A dev-only package — install it with `--dev`, it never
ships to production — with test machinery for Laravel packages and applications: base test cases,
a structural migration-order pin, a both-directions config contract, a secret-safe `about` capture
and architecture presets, each built so it can always fail.

## Installation

Requires PHP 8.4, Laravel 12 or 13, and Pest 4 (or Pest 5 on Laravel 13).

```bash
composer require --dev roundly-consulting/testing-for-laravel --with-all-dependencies
```

`--with-all-dependencies` lets Composer move the `phpunit/phpunit` version a fresh Laravel app
locks to one your Pest major supports (PHPUnit 12 for Pest 4, PHPUnit 13 for Pest 5).

## Usage

Point your package's `tests/TestCase.php` at the base case (in-memory SQLite, foreign keys on,
migrations loaded by provider class):

```php
use RoundlyConsulting\Testing\PackageTestCase;
use RoundlyConsulting\Wallet\WalletServiceProvider;

abstract class TestCase extends PackageTestCase
{
    protected function packageProviders(): array
    {
        return [WalletServiceProvider::class];
    }

    protected function migrationSources(): array
    {
        return [WalletServiceProvider::class];
    }
}
```

Then pin what the package ships — every assertion fails over an empty parse instead of passing:

```php
use RoundlyConsulting\Testing\Arch\ArchPresets;

it('has a runnable migration order', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toHaveRunnableMigrationOrder(foreignKeys: 3, externalTables: ['users']);
});

it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/wallet.php')->toSatisfyConfigContract(__DIR__.'/../../src');
});

ArchPresets::strictTypes('RoundlyConsulting\Wallet');
ArchPresets::finalByDefault('RoundlyConsulting\Wallet');
ArchPresets::noDebuggingLeftovers();
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/testing-for-laravel](https://roundly-consulting.com/open-source/docs/testing-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=testing-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=testing-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=testing-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

MIT. See [LICENSE.md](LICENSE.md).
