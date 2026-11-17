<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Coaching sessions (bookable times) and monthly memberships for SBA Creators. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('capacity');
        });

        Schema::create('creator_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('creator_products')->cascadeOnDelete();
            $table->timestamp('starts_at');
            // Held for a buyer while they pay, then booked once payment is confirmed.
            $table->unsignedBigInteger('purchase_id')->nullable()->index();
            $table->timestamp('held_until')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'starts_at']);
        });

        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('slot_id')->nullable()->after('checked_in_at');
            $table->timestamp('access_until')->nullable()->after('slot_id');
            $table->timestamp('renew_reminded_at')->nullable()->after('access_until');
            $table->timestamp('ended_notified_at')->nullable()->after('renew_reminded_at');
        });
    }

    public function down(): void
    {
        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->dropColumn(['slot_id', 'access_until', 'renew_reminded_at', 'ended_notified_at']);
        });
        Schema::dropIfExists('creator_slots');
        Schema::table('creator_products', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};
