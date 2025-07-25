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
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique(); // Unique ID from Stripe
            $table->string('type'); // e.g., 'payment_intent.succeeded'
            $table->jsonb('payload'); // Full event payload
            $table->string('status')->default('received'); // e.g., 'received', 'processing', 'processed', 'failed'
            $table->text('error_message')->nullable(); // Store error details if processing fails
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
