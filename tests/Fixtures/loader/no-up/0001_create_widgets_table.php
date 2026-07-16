<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

// A Migration subclass that declares no up(). `Migration` itself declares neither up()
// nor down(), so nothing but an explicit guard catches this — the same hole
// Migrator::runMigration() falls through when it skips a migration in silence.
return new class extends Migration {};
