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
        Schema::table('transactions', function (Blueprint $table) {
            // Add dual currency support
            $table->decimal('amount_usd', 15, 2)->nullable()->after('amount');
            $table->decimal('amount_naira', 15, 2)->nullable()->after('amount_usd');
            $table->decimal('exchange_rate', 10, 4)->nullable()->after('amount_naira');

            // Update payment_provider to support Paystack
            $table->string('payment_provider', 50)->default('stripe')->change();

            // Add Paystack-specific fields
            $table->string('paystack_reference')->nullable()->unique()->after('payment_intent_id');
            $table->string('paystack_authorization_code')->nullable()->after('paystack_reference');
            $table->json('paystack_response')->nullable()->after('meta_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'amount_usd',
                'amount_naira',
                'exchange_rate',
                'paystack_reference',
                'paystack_authorization_code',
                'paystack_response'
            ]);

            // Reset payment_provider to only stripe
            $table->string('payment_provider', 50)->default('stripe')->change();
        });
    }
};