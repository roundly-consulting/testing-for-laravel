<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Structurally fine — a real, non-empty down(). But up() creates two tables and down()
// drops one, so the set does not unwind. This is what the behavioural half is for: the
// structural half cannot see it, and the leftover table is what fails the *next* test.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
        });

        Schema::create('widget_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('widget_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('widget_tags');
    }
};
