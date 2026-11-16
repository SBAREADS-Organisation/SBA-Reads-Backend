<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_products', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('location')->nullable();
            // An online event's joining link, shown only to ticket holders.
            $table->string('online_url', 2048)->nullable();
            $table->unsignedInteger('capacity')->nullable();
        });

        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->string('ticket_code', 16)->nullable()->unique();
            $table->timestamp('checked_in_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->dropColumn(['ticket_code', 'checked_in_at']);
        });
        Schema::table('creator_products', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'timezone', 'location', 'online_url', 'capacity']);
        });
    }
};
