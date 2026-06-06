<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Paystack Transfer Recipient code — required for NGN author payouts.
            // Created via POST /transferrecipient and stored here so the payout
            // job can initiate transfers without re-creating the recipient each time.
            $table->string('paystack_recipient_code')->nullable()->after('kyc_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('paystack_recipient_code');
        });
    }
};
