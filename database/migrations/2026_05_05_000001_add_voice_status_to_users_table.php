<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('voice_status')->default('none')->after('elevenlabs_voice_id');
        });

        // Backfill: users who already have a cloned voice are ready
        DB::table('users')
            ->whereNotNull('elevenlabs_voice_id')
            ->where('elevenlabs_voice_id', '!=', '')
            ->update(['voice_status' => 'ready']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('voice_status');
        });
    }
};
