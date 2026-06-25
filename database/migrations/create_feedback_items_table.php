<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_items', function (Blueprint $t) {
            $t->id();
            $t->string('project')->default('default')->index();
            $t->nullableMorphs('reporter');
            $t->string('reporter_name')->nullable();
            $t->string('reporter_email')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('type')->default('bug');
            $t->string('status')->default('new')->index();
            $t->string('priority')->nullable();
            $t->nullableMorphs('assignee');
            $t->json('meta')->nullable();
            $t->json('external_refs')->nullable();
            $t->json('ai_state')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_items'); }
};
