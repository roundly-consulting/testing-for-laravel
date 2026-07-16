<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// A down() but no up(). `Migration` declares neither, so nothing but an explicit guard
// catches this — the same hole Migrator::runMigration() falls through for down().
return new class extends Migration
{
    public function down(): void
    {
        Schema::dropIfExists('widgets');
    }
};
