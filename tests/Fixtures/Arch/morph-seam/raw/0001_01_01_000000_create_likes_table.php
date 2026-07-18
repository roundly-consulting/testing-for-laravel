<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The regression this pin exists to catch: a migration that bypasses the morphKey
// seam and hardcodes a bigint morph id via the raw Blueprint helper.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('likes', function (Blueprint $table): void {
            $table->id();
            $table->morphs('likeable');
            $table->timestamps();
        });
    }
};
