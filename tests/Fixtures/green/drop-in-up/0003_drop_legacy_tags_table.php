<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// up() retires `legacy_tags`; down() puts it back — and down() never runs on the way up. Its
// CREATE (and the key inside it) must not count: the order pin is about the forward run.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_tag_id');
        });

        Schema::drop('legacy_tags');
    }

    public function down(): void
    {
        Schema::create('legacy_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners');
        });
    }
};
