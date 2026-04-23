<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->unsignedInteger('stock_quantity')->default(0)->after('actual_price');
            $table->unsignedInteger('stock_reserved')->default(0)->after('stock_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['stock_quantity', 'stock_reserved']);
        });
    }
};
