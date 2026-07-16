<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bug #1 in the flesh: no down(). Migrator::runMigration() guards down() with
// method_exists, so rolling this back is a silent no-op — `books` survives and the
// NEXT test dies creating `authors`, naming 0001. The culprit is this file.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained();
            $table->timestamps();
        });
    }
};
