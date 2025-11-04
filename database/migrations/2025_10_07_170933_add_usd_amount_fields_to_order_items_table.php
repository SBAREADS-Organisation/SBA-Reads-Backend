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
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('total_price_usd', 10, 2)->nullable()->after('total_price');
            $table->decimal('author_payout_amount_usd', 10, 2)->nullable()->after('author_payout_amount');
            $table->decimal('platform_fee_amount_usd', 10, 2)->nullable()->after('platform_fee_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('total_price_usd');
            $table->dropColumn('author_payout_amount_usd');
            $table->dropColumn('platform_fee_amount_usd');
        });
    }
};
