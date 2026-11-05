<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reading_progress', function (Blueprint $table) {
            // Separate from `bookmarks`, which released app versions overwrite on every page save.
            $table->jsonb('page_bookmarks')->nullable();
            $table->jsonb('notes')->nullable();
            $table->jsonb('audio_position')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reading_progress', function (Blueprint $table) {
            $table->dropColumn(['page_bookmarks', 'notes', 'audio_position']);
        });
    }
};
