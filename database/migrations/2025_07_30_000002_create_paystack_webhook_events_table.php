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
        Schema::create('paystack_webhook_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('paystack_event_id')->unique();
            $table->string('event_type');
            $table->string('paystack_reference')->nullable();
            $table->string('paystack_transaction_id')->nullable();

            // Event data
            $table->json('payload');
            $table->json('response')->nullable();

            // Processing status
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['event_type', 'status']);
            $table->index('paystack_reference');
            $table->index('paystack_transaction_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paystack_webhook_events');
    }
};
