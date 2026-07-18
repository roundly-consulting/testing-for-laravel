<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * A prose mention of a raw call must not trip the ban: we deliberately migrated off
 * $table->morphs('notable') and $table->nullableMorphs('notable') onto the seam. A
 * string literal 'morphs(' must not trip it either.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacyHelper = 'morphs('; // once upon a time this column used morphs()

        Schema::create('notes', function (Blueprint $table) use ($legacyHelper): void {
            $table->id();
            $table->morphKey('notable', KeyType::fromConfig('notes.key_type'), nullable: true);
            $table->string('body')->comment($legacyHelper);
            $table->timestamps();
        });
    }
};
