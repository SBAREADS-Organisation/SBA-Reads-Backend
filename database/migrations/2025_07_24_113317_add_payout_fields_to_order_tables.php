<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('payout_status', ['pending', 'initiated', 'completed', 'failed'])->default('pending')->after('status');
            $table->decimal('platform_fee_amount', 10, 2)->default(0.00)->after('total_amount');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('author_id')->constrained('users');
            $table->decimal('author_payout_amount', 10, 2)->default(0.00)->after('total_price');
            $table->decimal('platform_fee_amount', 10, 2)->default(0.00)->after('author_payout_amount');
            $table->enum('payout_status', ['pending', 'initiated', 'completed', 'failed'])->default('pending')->after('platform_fee_amount');
            $table->string('payout_error')->nullable()->after('payout_status');
            $table->string('stripe_transfer_id')->nullable()->after('payout_error'); // For tracking payouts to authors
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payout_status', 'platform_fee_amount']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['author_id', 'author_payout_amount', 'platform_fee_amount', 'payout_status', 'payout_error', 'stripe_transfer_id']);
        });
    }
};
