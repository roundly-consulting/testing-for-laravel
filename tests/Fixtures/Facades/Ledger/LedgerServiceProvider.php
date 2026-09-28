<?php

declare(strict_types=1);

namespace RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Testing\Tests\Fixtures\Facades\Ledger\Contracts\Ledger;

final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ledger::class, LedgerManager::class);
    }
}
