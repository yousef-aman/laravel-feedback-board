<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_activities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feedback_item_id')->constrained('feedback_items')->cascadeOnDelete();
            $t->nullableMorphs('actor');
            $t->string('action');
            $t->string('from_status')->nullable();
            $t->string('to_status')->nullable();
            $t->json('properties')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_activities'); }
};
