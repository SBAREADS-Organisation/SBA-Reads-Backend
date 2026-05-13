<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('is_featured')->default(false)->after('status');
            $table->unsignedSmallInteger('ranking')->nullable()->after('is_featured'); // lower = higher priority for ads/placement
            $table->decimal('audio_price', 8, 2)->default(10.00)->after('ranking');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['is_featured', 'ranking', 'audio_price']);
        });
    }
};
