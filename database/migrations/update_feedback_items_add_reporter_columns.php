<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('feedback_items', function (Blueprint $t) {
            $t->timestamp('reporter_read_at')->nullable();
            $t->timestamp('reporter_activity_at')->nullable();
        });

        // No reply sent before the upgrade turns unread, and each reporter's list keeps its order.
        DB::table('feedback_items')->update([
            'reporter_read_at' => now(),
            'reporter_activity_at' => DB::raw(DB::getQueryGrammar()->wrap('updated_at')),
        ]);

        Schema::table('feedback_comments', function (Blueprint $t) {
            $t->index(['feedback_item_id', 'is_internal', 'created_at'], 'feedback_comments_unread_index');
        });
    }

    public function down(): void {
        Schema::table('feedback_comments', function (Blueprint $t) {
            $t->dropIndex('feedback_comments_unread_index');
        });

        Schema::table('feedback_items', function (Blueprint $t) {
            $t->dropColumn(['reporter_read_at', 'reporter_activity_at']);
        });
    }
};
