<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Re-creates a table an earlier migration already created — a real engine (and
    // SQLite) rejects the second CREATE, so `toApplyOnConnection` must fail here.
    public function up(): void
    {
        Schema::create('things', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });
    }
};
