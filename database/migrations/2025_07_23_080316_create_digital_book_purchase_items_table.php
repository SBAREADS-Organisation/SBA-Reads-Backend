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
        Schema::create('digital_book_purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('digital_book_purchase_id')
                ->constrained('digital_book_purchases')
                ->onDelete('cascade');
            $table->foreignId('book_id')
                ->constrained('books')
                ->onDelete('cascade');
            $table->foreignId('author_id')
                ->constrained('users')
                ->onDelete('cascade');
            $table->integer('quantity')->default(1);
            $table->decimal('price_at_purchase', 10, 2);
            $table->decimal('author_payout_amount', 10, 2)->nullable();
            $table->decimal('platform_fee_amount', 10, 2)->nullable();
            $table->enum('payout_status', ['pending', 'initiated', 'completed', 'failed'])->default('pending'); // pending, completed, failed
            $table->string('stripe_transfer_id')->nullable(); // For tracking payouts to authors
            $table->string('payout_error')->nullable(); // For storing any payout errors
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('digital_book_purchase_items');
    }
};
