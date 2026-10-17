<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\MigrationRuns;

return new class extends Migration
{
    public function up(): void
    {
        MigrationRuns::record('kept');

        Schema::create('kept_owners', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
        });

        Schema::create('kept_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kept_owner_id')->constrained('kept_owners')->cascadeOnDelete();
            $table->string('label')->unique();
        });
    }
};
