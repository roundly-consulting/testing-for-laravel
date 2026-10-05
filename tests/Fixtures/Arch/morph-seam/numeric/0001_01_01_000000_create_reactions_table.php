<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// numericMorphs() hardcodes an unsignedBigInteger id — morphs() itself delegates to it — so it
// bypasses the morphKey seam exactly as morphs() does.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactions', function (Blueprint $table): void {
            $table->id();
            $table->numericMorphs('likeable');
            $table->nullableNumericMorphs('source');
        });
    }
};
