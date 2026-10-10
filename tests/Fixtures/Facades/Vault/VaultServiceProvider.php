<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Keyring;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Vault\Contracts\Locker;

final class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Keyring::class, KeyringManager::class);
        $this->app->singleton(Locker::class, LockerManager::class);
    }
}
