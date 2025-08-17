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
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('reference')->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('usd');
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed', 'cancelled'])->default('pending');
            $table->string('withdrawal_method')->default('bank_transfer');
            $table->string('bank_account_id')->nullable();
            $table->string('stripe_transfer_id')->nullable();
            $table->json('payout_data')->nullable();
            $table->json('meta_data')->nullable();
            $table->text('description')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['reference']);
            $table->index(['stripe_transfer_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};