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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('reference')->unique();
            $table->string('payment_intent_id')->unique()->nullable();
            $table->string('payment_client_secret')->unique()->nullable();
            $table->enum('status', ['pending', 'succeeded', 'failed', 'processing', 'refunded', 'available', 'requested', 'declined', 'approved', 'sent', 'settled', 'completed', 'locked', 'withdrawn', 'on_hold', 'expired'])->default('pending');
            $table->string('currency', 10)->default('usd');
            $table->decimal('amount', 15, 2);
            // description
            $table->string('description')->nullable();
            $table->string('payment_provider')->default('stripe');
            $table->enum('type', ['purchase', 'earning', 'payout', 'refund', 'fee', 'bonus', 'adjustment', 'others'])->default('debit');
            // purchased_by
            $table->foreignId('purchased_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('direction', ['credit', 'debit', 'other'])->nullable();
            $table->timestamp('available_at')->nullable();
            $table->json('payout_data')->nullable();
            // $table->timestamp('payout_requested_at')->nullable();
            // $table->timestamp('payout_approved_at')->nullable();
            // $table->timestamp('payout_sent_at')->nullable();
            // $table->timestamp('payout_settled_at')->nullable();

            // Polymorphic purpose
            $table->string('purpose_type'); // Example: App\Models\Order, App\Models\Subscription
            $table->string('purpose_id');

            $table->json('meta_data')->nullable();

            $table->timestampsTz(); // UTC timestamps
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
