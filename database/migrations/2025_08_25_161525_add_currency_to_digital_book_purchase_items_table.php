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
        // Add currency column to digital_book_purchase_items table
        Schema::table('digital_book_purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('digital_book_purchase_items', 'currency')) {
                $table->string('currency')->default('USD')->after('amount');
            }
        });

        // Add currency column to orders table
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'currency')) {
                $table->string('currency')->default('USD')->after('total_amount');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('digital_book_purchase_items', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
