<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('digital_book_purchase_items', function (Blueprint $table) {
            // Add payment provider field
            $table->enum('payment_provider', ['stripe', 'paystack'])->nullable()->after('payout_status');
            
            // Rename stripe_transfer_id to generic provider_transfer_id
            $table->renameColumn('stripe_transfer_id', 'provider_transfer_id');
        });
    }

    public function down()
    {
        Schema::table('digital_book_purchase_items', function (Blueprint $table) {
            $table->dropColumn('payment_provider');
            $table->renameColumn('provider_transfer_id', 'stripe_transfer_id');
        });
    }
};