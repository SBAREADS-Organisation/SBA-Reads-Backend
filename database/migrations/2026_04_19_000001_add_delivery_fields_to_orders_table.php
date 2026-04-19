<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('delivery_type', ['delivery', 'pickup'])->default('delivery')->after('delivery_address_id');
            $table->string('contact_name')->nullable()->after('delivery_type');
            $table->string('contact_phone', 20)->nullable()->after('contact_name');
            $table->string('delivery_state', 100)->nullable()->after('contact_phone');
            $table->string('delivery_country', 100)->nullable()->after('delivery_state');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_type', 'contact_name', 'contact_phone', 'delivery_state', 'delivery_country']);
        });
    }
};
