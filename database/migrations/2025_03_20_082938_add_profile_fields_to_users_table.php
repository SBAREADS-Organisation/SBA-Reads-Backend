<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // $table->text('bio')->nullable()->default('')->after('account_type');
            // $table->jsonb('settings')->nullable()->default('{}')->after('bio');
            // $table->jsonb('preferences')->nullable()->default('{}')->after('username');
            $table->json('profile_picture')->nullable()->default('{}')->after('pronouns');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([/* 'settings', 'preferences', */ 'profile_picture']);
        });
    }
};
