<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

// The correct shape: every morph column goes through the morphKey seam, so its id
// key type follows the host config instead of a hardcoded bigint.
return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('likes.key_type');

        Schema::create('likes', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('actor', $keyType, nullable: false);
            $table->morphKey('likeable', $keyType, nullable: false);
            $table->timestamps();
        });
    }
};
