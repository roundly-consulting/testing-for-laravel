<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A named-class migration — the pre-Laravel-9 shape the Migrator still runs: the file returns
// nothing, and the class is resolved from the file name. Requiring it twice redeclares it.
class CreateNamedClassWidgetsTable extends Migration
{
    public function up(): void
    {
        Schema::create('named_class_widgets', function (Blueprint $table): void {
            $table->id();
        });
    }
}
