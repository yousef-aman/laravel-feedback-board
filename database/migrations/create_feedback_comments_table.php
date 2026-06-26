<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feedback_item_id')->constrained('feedback_items')->cascadeOnDelete();
            $t->nullableMorphs('author');
            $t->string('author_role')->default('developer');
            $t->text('body');
            $t->boolean('is_internal')->default(false)->index();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_comments'); }
};
