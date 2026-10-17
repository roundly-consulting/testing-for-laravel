<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A counter a fresh migrate leaves at 100, not 1: the reset must put it back there.
     */
    public function up(): void
    {
        Schema::create('numbered_things', function (Blueprint $table): void {
            $table->id()->startingValue(100);
            $table->string('label');
        });

        // SQLite's grammar has no starting value; AUTOINCREMENT reads it from sqlite_sequence.
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('sqlite_sequence')->insert(['name' => 'numbered_things', 'seq' => 99]);
        }
    }
};
