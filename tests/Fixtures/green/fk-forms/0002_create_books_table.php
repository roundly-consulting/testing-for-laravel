<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Tests\Fixtures\Migrations\Author;
use RoundlyConsulting\Testing\Tests\Fixtures\Migrations\Imprint as Publisher;

// Every foreign-key form Laravel documents beyond the literal/bare/long-hand three.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Author::class)->constrained();                        // authors
            $table->foreignIdFor(Publisher::class, 'imprint_ref')->constrained();      // imprints_catalogue (from the model)
            $table->foreignId('editor_id')->constrained('authors', 'id');               // table + column
            $table->foreignId('reviewer_id')->nullable()->constrained(table: 'authors'); // named argument
            $table->foreignId('translator_id')->constrained(column: 'id', table: 'authors', indexName: 'books_translator_fk');
            $table->foreignUuid('owner_uuid')->constrained(column: 'uuid');             // owners, derived past `_uuid`
            $table->foreignId('owner_id')->constrained(null, 'id');                     // an explicit null table is still derived: owners
        });
    }
};
