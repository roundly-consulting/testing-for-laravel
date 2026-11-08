<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Concerns;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Assert;
use RuntimeException;

/**
 * Points `config/`, `database/` and `app/` at a throwaway directory per test, for a suite that
 * publishes, installs or generates files — never the shared Testbench skeleton.
 *
 * Every `pest --parallel` process boots from the same skeleton: Testbench requires each
 * `config/*.php` there, `migrate` runs each file in `database/migrations`. A test that writes
 * into it fails some *other* test when a file appears or vanishes mid-boot ("Path must not be
 * empty" in `LoadConfiguration`, a migration run twice), and a leftover is loaded by every later
 * run. The fleet carried ten hand-written copies of this case; this is the one to use.
 *
 * ```php
 * // tests/Pest.php — or `use UsesPublishSandbox;` on a directory-scoped base case
 * uses(TestCase::class, UsesPublishSandbox::class)->in('Publish');
 *
 * // tests/Publish/PublishTest.php
 * it('publishes into the sandbox, never the shared skeleton', function (): void {
 *     $this->assertPublishesIntoSandbox('credits-config', 'credits-migrations');
 * });
 * ```
 *
 * The paths move **before the providers register**, in `getPackageProviders()`: a provider that
 * maps `config_path(...)` while it registers fixes its destination then, so a sandbox set in
 * `defineEnvironment()` moves the helper and leaves the publish pointed at the skeleton. The
 * configuration has already loaded from the skeleton by then, so the suite boots as usual.
 * `app/` moves after the application namespace is resolved — Laravel derives it by matching
 * `app/` against the skeleton's `composer.json`, which a sandbox would not match — so `make:*`
 * generators keep working.
 *
 * The directory is deleted when the test ends. An override of `getPackageProviders()` must still
 * reach the trait's — `parent::getPackageProviders($app)` from a subclass, or the trait method
 * aliased in the class that uses it — or setUp refuses to run rather than publish into the
 * skeleton.
 */
trait UsesPublishSandbox
{
    /**
     * The sandbox root for this test, or `''` before the application exists.
     */
    private string $publishSandboxRoot = '';

    /**
     * Which application paths move into the sandbox: any of `config`, `database`, `app`.
     *
     * @return list<string>
     */
    protected function publishSandboxPaths(): array
    {
        return ['config', 'database', 'app'];
    }

    /**
     * Put files the host already has into the sandbox, before the providers register — a
     * publish that must recognise an existing file needs it there by then.
     */
    protected function preparePublishSandbox(string $root): void {}

    /**
     * The sandbox root, or a path inside it.
     */
    protected function publishSandbox(string $path = ''): string
    {
        if ($this->publishSandboxRoot === '') {
            throw new LogicException('The publish sandbox does not exist: the application has not been created yet.');
        }

        return $path === '' ? $this->publishSandboxRoot : $this->publishSandboxRoot.'/'.ltrim($path, '/');
    }

    /**
     * Pin that this test publishes into its sandbox, never the shared skeleton: every sandboxed
     * path helper resolves inside it, and every destination the given publish tags map does too.
     * A tag that maps nothing fails — an empty mapping would pass without checking anything.
     */
    protected function assertPublishesIntoSandbox(string ...$tags): void
    {
        $root = $this->publishSandbox().'/';

        foreach ($this->sandboxedPathHelpers() as $kind => $path) {
            Assert::assertStringStartsWith(
                $root,
                $path.'/',
                "The {$kind} path [{$path}] is outside the publish sandbox [{$root}]: something moved it back.",
            );
        }

        foreach ($tags as $tag) {
            $destinations = array_values(ServiceProvider::pathsToPublish(null, $tag));

            Assert::assertNotEmpty(
                $destinations,
                "Nothing publishes under the tag [{$tag}]: an empty mapping proves nothing. Check the tag name.",
            );

            foreach ($destinations as $destination) {
                Assert::assertStringStartsWith(
                    $root,
                    $destination,
                    "The tag [{$tag}] publishes to [{$destination}], outside the sandbox [{$root}] — into the shared "
                    .'skeleton the parallel suite boots from. A destination fixed before the sandbox moved, or a path '
                    .'this test case does not sandbox (see publishSandboxPaths()).',
                );
            }
        }
    }

    /**
     * @param  Application  $app
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        if ($this->publishSandboxRoot === '') {
            $this->publishSandboxRoot = $this->createPublishSandbox($app);
        }

        return parent::getPackageProviders($app);
    }

    /**
     * Refuse to run a test whose providers registered against the skeleton.
     */
    protected function setUpUsesPublishSandbox(): void
    {
        if ($this->publishSandboxRoot === '') {
            throw new LogicException(
                static::class.' uses UsesPublishSandbox, but the sandbox was never created: an override of '
                .'getPackageProviders() hides the trait\'s. Call parent::getPackageProviders($app) from a subclass, '
                .'alias the trait method in the class that uses it, or declare the providers in packageProviders().',
            );
        }
    }

    /**
     * Delete the sandbox. Runs when the application is destroyed; safe to call twice.
     */
    protected function tearDownUsesPublishSandbox(): void
    {
        $root = $this->publishSandboxRoot;

        if ($root !== '' && str_starts_with($root, rtrim(sys_get_temp_dir(), '/').'/') && is_dir($root)) {
            (new Filesystem)->deleteDirectory($root);
        }
    }

    private function createPublishSandbox(Application $app): string
    {
        $kinds = $this->publishSandboxPaths();

        foreach ($kinds as $kind) {
            if (! in_array($kind, ['config', 'database', 'app'], true)) {
                throw new InvalidArgumentException(
                    "publishSandboxPaths() names [{$kind}]; the publish sandbox moves config, database and app.",
                );
            }
        }

        $root = rtrim(sys_get_temp_dir(), '/').'/roundly-publish-sandbox-'.bin2hex(random_bytes(8));
        // A plain Filesystem: the providers that bind the File facade have not registered yet.
        $files = new Filesystem;

        if (in_array('config', $kinds, true)) {
            $files->ensureDirectoryExists($root.'/config');
            $app->useConfigPath($root.'/config');
        }

        if (in_array('database', $kinds, true)) {
            $files->ensureDirectoryExists($root.'/database/migrations');
            $app->useDatabasePath($root.'/database');
        }

        if (in_array('app', $kinds, true)) {
            $this->resolveApplicationNamespace($app);
            $files->ensureDirectoryExists($root.'/app');
            $app->useAppPath($root.'/app');
        }

        $files->ensureDirectoryExists($root);
        $this->preparePublishSandbox($root);

        return $root;
    }

    /**
     * Cache the namespace while `app/` still matches the skeleton's composer.json. A skeleton
     * without one has no namespace to keep; a generator would report that itself.
     */
    private function resolveApplicationNamespace(Application $app): void
    {
        try {
            $app->getNamespace();
        } catch (RuntimeException) {
            // Nothing to keep.
        }
    }

    /**
     * @return array<string, string>
     */
    private function sandboxedPathHelpers(): array
    {
        $helpers = [];

        foreach ($this->publishSandboxPaths() as $kind) {
            $helpers[$kind] = match ($kind) {
                'config' => config_path(),
                'database' => database_path(),
                default => app_path(),
            };
        }

        return $helpers;
    }
}
