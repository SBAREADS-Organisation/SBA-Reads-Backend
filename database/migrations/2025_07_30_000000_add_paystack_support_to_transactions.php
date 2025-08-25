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
        if (!Schema::hasTable('transactions')) {
            // Fresh install: columns are already defined in create_payments_table
            return;
        }

        Schema::table('transactions', function (Blueprint $table) {
            // Add dual currency support if missing
            if (!Schema::hasColumn('transactions', 'amount_usd')) {
                $table->decimal('amount_usd', 15, 2)->nullable()->after('amount');
            }
            if (!Schema::hasColumn('transactions', 'amount_naira')) {
                $table->decimal('amount_naira', 15, 2)->nullable()->after('amount_usd');
            }
            if (!Schema::hasColumn('transactions', 'exchange_rate')) {
                $table->decimal('exchange_rate', 10, 4)->nullable()->after('amount_naira');
            }

            // Ensure payment_provider length supports Paystack (skip change on fresh)
            // Note: "change()" requires doctrine/dbal on some DBs; guard accordingly in fresh installs
            if (Schema::hasColumn('transactions', 'payment_provider')) {
                // $table->string('payment_provider', 50)->default('stripe')->change();
            }

            // Add Paystack-specific fields if missing
            if (!Schema::hasColumn('transactions', 'paystack_reference')) {
                $table->string('paystack_reference')->nullable()->unique()->after('payment_intent_id');
            }
            if (!Schema::hasColumn('transactions', 'paystack_authorization_code')) {
                $table->string('paystack_authorization_code')->nullable()->after('paystack_reference');
            }
            if (!Schema::hasColumn('transactions', 'paystack_response')) {
                $table->json('paystack_response')->nullable()->after('meta_data');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {

        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'amount_usd')) {
                return;
            }
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