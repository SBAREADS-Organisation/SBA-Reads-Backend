<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_kyc_infos', function (Blueprint $table) {
            $table->string('state')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Set any null state values to empty string before reverting nullable
        \Illuminate\Support\Facades\DB::statement("UPDATE user_kyc_infos SET state = '' WHERE state IS NULL");
        Schema::table('user_kyc_infos', function (Blueprint $table) {
            $table->string('state')->nullable(false)->change();
        });
    }
};
