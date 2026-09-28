<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A migration set that builds on a table it does not own — the host app's `users`, created
// by a migration outside this directory.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('plan')->nullable();
        });
    }
};
