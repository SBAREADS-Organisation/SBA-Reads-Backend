<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_book_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('book_id')->constrained()->onDelete('cascade');
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->decimal('price', 8, 2)->default(10.00);           // flat rate charged to buyer (USD)
            $table->decimal('author_payout_amount', 8, 2);             // 30% of price
            $table->decimal('platform_fee_amount', 8, 2);              // 70% of price
            $table->decimal('price_converted', 8, 2)->nullable();      // price in buyer's currency
            $table->string('currency')->default('USD');
            $table->enum('status', ['pending', 'paid', 'failed'])->default('pending');
            $table->enum('payout_status', ['pending', 'paid', 'failed'])->default('pending');
            $table->string('payment_provider')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'book_id']); // one audio purchase per user per book
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_book_purchases');
    }
};
