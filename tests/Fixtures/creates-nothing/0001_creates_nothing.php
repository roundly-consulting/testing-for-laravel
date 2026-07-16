<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

// Applies without error and creates nothing. "The migrations applied cleanly" is true of
// this set and proves nothing — the vacuous green the non-empty-schema guard exists to kill.
return new class extends Migration
{
    public function up(): void
    {
        //
    }
};
