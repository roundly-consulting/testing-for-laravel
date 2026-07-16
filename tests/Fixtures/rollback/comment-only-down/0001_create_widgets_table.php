<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A body made of comments only. A regex over the source would read this as "has a
// down() with a body" — the shape of the config-contract bug where a docblock satisfied
// the check. The tokenizer must see through it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
        });
    }

    public function down(): void
    {
        /** @todo drop the table */
        // Schema::dropIfExists('widgets');
    }
};
