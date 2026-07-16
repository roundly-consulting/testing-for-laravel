<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The obvious way to silence a down() assertion: declare one that does nothing. It is
// the missing-down() no-op with extra steps, so it must go red exactly the same way.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void {}
};
