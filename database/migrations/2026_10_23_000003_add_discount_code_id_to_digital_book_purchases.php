<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_book_purchases', function (Blueprint $table) {
            $table->foreignId('discount_code_id')
                ->nullable()
                ->after('platform_fee_amount')
                ->constrained('discount_codes')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('digital_book_purchases', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\DiscountCode::class);
            $table->dropColumn('discount_code_id');
        });
    }
};
