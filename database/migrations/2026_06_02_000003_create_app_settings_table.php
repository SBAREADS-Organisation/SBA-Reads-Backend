<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Seed defaults
        DB::table('app_settings')->insert([
            ['key' => 'ai_auto_approve_books',   'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'ai_auto_approve_authors', 'value' => 'false', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'ai_confidence_threshold', 'value' => '0.85',  'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
