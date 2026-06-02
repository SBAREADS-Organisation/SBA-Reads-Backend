<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->enum('ai_review_status', ['pending', 'approved', 'declined', 'human_review'])
                  ->nullable()
                  ->after('rejection_note');
            $table->text('ai_review_notes')->nullable()->after('ai_review_status');
            $table->decimal('ai_review_confidence', 3, 2)->nullable()->after('ai_review_notes');
            $table->timestamp('ai_reviewed_at')->nullable()->after('ai_review_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['ai_review_status', 'ai_review_notes', 'ai_review_confidence', 'ai_reviewed_at']);
        });
    }
};
