<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Fails for a reason that has nothing to do with order: a syntax error is refused by every
// engine in every order, so counting it as "the engine rejected the broken order" would make
// the negative control pass on a set whose order was never tested.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('this is not sql');
    }
};
