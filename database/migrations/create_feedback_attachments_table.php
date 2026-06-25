<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('feedback_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feedback_item_id')->constrained('feedback_items')->cascadeOnDelete();
            $t->foreignId('feedback_comment_id')->nullable()->constrained('feedback_comments')->nullOnDelete();
            $t->string('disk');
            $t->string('path');
            $t->string('original_name');
            $t->string('mime')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('feedback_attachments'); }
};
