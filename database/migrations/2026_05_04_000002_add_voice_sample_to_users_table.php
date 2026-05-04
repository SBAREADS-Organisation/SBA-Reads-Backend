<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('voice_sample_url')->nullable()->after('device_token');
            $table->string('elevenlabs_voice_id')->nullable()->after('voice_sample_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['voice_sample_url', 'elevenlabs_voice_id']);
        });
    }
};
