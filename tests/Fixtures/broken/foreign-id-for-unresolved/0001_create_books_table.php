<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $model = config('books.author_model');

        Schema::create('books', function (Blueprint $table) use ($model): void {
            $table->id();
            $table->foreignIdFor($model)->constrained();
        });
    }
};
