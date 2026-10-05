<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A key inside a connection-scoped block, and an ALTER through a computed connection name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('sqlite_real')->create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained();
        });

        Schema::connection($this->getConnection())->table('users', function (Blueprint $table): void {
            $table->string('nickname')->nullable();
        });
    }
};
