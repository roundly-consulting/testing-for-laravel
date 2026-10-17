<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Fixtures\KeptSchema\MigrationRuns;

return new class extends Migration
{
    public function up(): void
    {
        MigrationRuns::record('seeding');

        Schema::create('seeded_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
        });

        DB::table('seeded_settings')->insert([['key' => 'locale'], ['key' => 'timezone']]);
    }
};
