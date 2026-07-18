<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A package with migrations but no morph columns at all: legitimately passes, because
// the scan ran over a real file and found no raw morph — vacuity-safe, not vacuous.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }
};
