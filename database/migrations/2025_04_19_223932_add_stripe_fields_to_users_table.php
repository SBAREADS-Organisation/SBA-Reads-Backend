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
            $table->string('kyc_account_id')->nullable();
            $table->string('kyc_customer_id')->nullable();
            $table->string('kyc_provider')->nullable();
            $table->json('kyc_metadata')->nullable()->default(json_encode('{}'));
            $table->enum('kyc_status', ['pending', 'verified', 'rejected', 'document-required', 'in-review'])->default('pending');

            $table->index('kyc_status');
            $table->index('kyc_account_id');
            $table->index('kyc_customer_id');
            $table->index('kyc_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kyc_account_id', 'kyc_status', 'kyc_provider, kyc_metadata' /*'profile_picture'*/]);
            $table->dropIndex(['kyc_status', 'kyc_account_id', 'kyc_customer_id', 'kyc_provider']);
        });
    }
};
