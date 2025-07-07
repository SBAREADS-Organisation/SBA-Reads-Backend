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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('provider')->index(); // 'stripe', 'paystack', etc
            $table->string('provider_payment_method_id')->index(); // Stripe PM ID or Paystack reference
            $table->enum('type', ['card', 'bank'])->index(); // Type of payment method
            $table->string('country_code', 2)->nullable(); // 2-letter ISO country code
            $table->boolean('default')->default(false); // Is this the default payment method
            $table->enum('purpose', ['payment', 'payout'])->default('payment')->index(); // payment for buying, payout for sellers
            $table->json('payment_method_data'); // Stores card data or bank info, should be encrypted
            $table->json('metadata')->nullable(); // Any extra info (flexible)
            $table->timestampsTz(0); // Precise timestamps in UTC
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
