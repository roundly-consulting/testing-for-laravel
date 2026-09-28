<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The same two tables in one migration, child FIRST — a real engine refuses the FK because
// `teams` does not exist yet when `team_members` is created.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained();
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->id();
        });
    }
};
