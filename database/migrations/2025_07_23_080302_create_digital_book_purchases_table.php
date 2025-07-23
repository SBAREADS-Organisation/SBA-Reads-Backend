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
        Schema::create('digital_book_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // The reader who placed the order
            $table->decimal('total_amount', 10, 2); // Total amount of the entire order
            $table->decimal('platform_fee_amount', 10, 2)->default(0); // Platform fee amount charged for the purchase
            $table->string('currency')->default('USD'); // Currency of the transaction
            $table->string('stripe_payment_intent_id')->nullable(); // Stripe payment intent ID
            $table->enum('status', ['pending', 'paid', 'failed', 'payout_initiated', 'payout_completed', 'payout_failed'])->default('pending'); // Status of the purchase
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_book_purchases');
    }
};
