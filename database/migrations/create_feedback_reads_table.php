<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_reads', function (Blueprint $t) {
            $t->id();
            // NULL marks the developer's baseline row: their first board visit.
            $t->foreignId('feedback_item_id')->nullable()->constrained('feedback_items')->cascadeOnDelete();
            $t->morphs('reader');
            $t->timestamp('read_at');
            $t->unique(['reader_type', 'reader_id', 'feedback_item_id'], 'feedback_reads_reader_item_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_reads'); }
};
