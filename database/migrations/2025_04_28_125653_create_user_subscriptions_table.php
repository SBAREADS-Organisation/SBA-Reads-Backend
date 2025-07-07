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
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('subscription_id')->constrained()->onDelete('cascade');
            // $table->string('stripe_subscription_id')->nullable(); // from Stripe
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('status', ['active', 'expired', 'cancelled', 'pending', 'in-transaction'])->default('active');
            $table->timestampsTz(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};
