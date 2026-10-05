<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A migration with no Schema::create()/table() block: there is nothing for the order pin to
// order, so "runnable" would be a verdict over an empty parse.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('select 1');
    }
};
