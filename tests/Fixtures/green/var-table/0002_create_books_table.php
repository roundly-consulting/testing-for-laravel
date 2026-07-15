<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('books.table', 'books');

        Schema::create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->foreignId('author_id')->constrained();
            $table->timestamps();
        });
    }
};
