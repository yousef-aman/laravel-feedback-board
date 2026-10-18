<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_reads', function (Blueprint $t) {
            $t->id();
            // NULL marks the developer's baseline row: their first board visit.
            $t->foreignId('feedback_item_id')->nullable()->constrained('feedback_items')->cascadeOnDelete();
            // The columns morphs('reader') makes, without its (reader_type, reader_id) index: the
            // unique index below starts with those two columns and serves the same lookups.
            $t->string('reader_type');
            match (Builder::$defaultMorphKeyType) {
                'uuid' => $t->uuid('reader_id'),
                'ulid' => $t->ulid('reader_id'),
                default => $t->unsignedBigInteger('reader_id'),
            };
            $t->timestamp('read_at');
            $t->unique(['reader_type', 'reader_id', 'feedback_item_id'], 'feedback_reads_reader_item_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_reads'); }
};
