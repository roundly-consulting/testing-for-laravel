<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

// Renames a table nothing creates (and nobody declared external).
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('members', 'users');
    }
};
