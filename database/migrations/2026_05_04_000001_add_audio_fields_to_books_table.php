<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('audio_status')->default('none')->after('meta_data'); // none|pending|processing|ready|failed
            $table->text('audio_url')->nullable()->after('audio_status');
            $table->integer('audio_duration')->nullable()->after('audio_url'); // in seconds
            $table->json('audio_segments')->nullable()->after('audio_duration'); // array of segment URLs
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['audio_status', 'audio_url', 'audio_duration', 'audio_segments']);
        });
    }
};
