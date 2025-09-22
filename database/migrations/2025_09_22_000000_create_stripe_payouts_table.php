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
        Schema::create('stripe_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('stripe_payout_id')->unique()->index(); // e.g. po_1SADZXRtXi5ok2mJA3313PCl
            $table->decimal('amount', 15, 2); // Payout amount
            $table->string('currency', 3); // 3-letter currency code (USD, EUR, etc.)
            $table->enum('status', ['pending', 'in_transit', 'paid', 'failed', 'canceled'])->default('pending');
            $table->string('destination')->index(); // e.g. ba_1RoSQARtXi5ok2mJaXxh1Bml (bank account ID)
            $table->enum('destination_type', ['bank_account', 'card'])->default('bank_account');
            $table->unsignedInteger('arrival_date'); // Unix timestamp for when funds arrive
            $table->string('description')->nullable();
            $table->string('failure_code')->nullable(); // Stripe failure code if failed
            $table->text('failure_message')->nullable(); // Stripe failure message if failed
            $table->string('statement_descriptor')->nullable();
            $table->string('source_type')->default('card'); // Source of funds
            $table->boolean('automatic')->default(false); // Whether this was an automatic payout
            $table->unsignedInteger('created_stripe'); // Unix timestamp from Stripe
            $table->json('metadata')->nullable(); // Additional Stripe metadata
            $table->json('stripe_response')->nullable(); // Full Stripe response for debugging
            $table->timestamps();

            // Indexes for common queries
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
            $table->index(['currency', 'status']);
            $table->index(['destination_type', 'status']);
            $table->index(['arrival_date']);
            $table->index(['automatic', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stripe_payouts');
    }
};
