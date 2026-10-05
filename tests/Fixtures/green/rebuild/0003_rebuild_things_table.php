<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A forward-only rebuild: drop the table and create it again. The ALTER in 0002 ran against
// the FIRST create, which is live at its ordinal — the second create must not hide that.
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('things');

        Schema::create('things', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
            $table->foreignId('parent_id')->nullable()->constrained('things');
        });
    }
};
