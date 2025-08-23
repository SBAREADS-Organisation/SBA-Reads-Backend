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
        Schema::create('paystack_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('transaction_id');
            $table->foreign('transaction_id')->references('id')->on('transactions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Paystack-specific fields
            $table->string('paystack_reference')->unique();
            $table->string('paystack_transaction_id')->unique();
            $table->string('paystack_authorization_code')->nullable();
            $table->string('paystack_customer_code')->nullable();
            $table->string('paystack_plan_code')->nullable();
            $table->string('paystack_subscription_code')->nullable();

            // Payment details
            $table->decimal('amount_kobo', 15, 2);
            $table->decimal('amount_naira', 15, 2);
            $table->decimal('fees_kobo', 15, 2)->default(0);
            $table->string('currency', 10)->default('NGN');
            $table->string('gateway_response')->nullable();
            $table->string('channel')->nullable();
            $table->string('ip_address')->nullable();

            // Customer details
            $table->string('customer_email')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            // Status tracking
            $table->enum('status', ['pending', 'success', 'failed', 'abandoned', 'cancelled'])->default('pending');
            $table->timestamp('paid_at')->nullable();

            // Metadata
            $table->json('metadata')->nullable();
            $table->json('paystack_response')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paystack_transactions');
    }
};
