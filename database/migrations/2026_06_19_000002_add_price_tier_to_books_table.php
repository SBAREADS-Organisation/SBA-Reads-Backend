<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Tier product ID shared across books at the same price point.
            // Replaces per-book product_id for new books so no App Store / Play
            // Store submission is required each time a new title is published.
            $table->string('price_tier')->nullable()->after('actual_price');
            $table->string('audio_price_tier')->nullable()->after('audio_price');
        });

        // Backfill tiers for existing books based on their current prices.
        DB::statement("UPDATE books SET price_tier = CASE
            WHEN actual_price <= 0.99  THEN 'sba_book_tier_099'
            WHEN actual_price <= 1.99  THEN 'sba_book_tier_199'
            WHEN actual_price <= 2.99  THEN 'sba_book_tier_299'
            WHEN actual_price <= 4.99  THEN 'sba_book_tier_499'
            WHEN actual_price <= 9.99  THEN 'sba_book_tier_999'
            WHEN actual_price <= 14.99 THEN 'sba_book_tier_1499'
            WHEN actual_price <= 19.99 THEN 'sba_book_tier_1999'
            ELSE 'sba_book_tier_2499'
        END WHERE actual_price IS NOT NULL");

        DB::statement("UPDATE books SET audio_price_tier = CASE
            WHEN audio_price <= 0.99  THEN 'sba_book_tier_099_audio'
            WHEN audio_price <= 1.99  THEN 'sba_book_tier_199_audio'
            WHEN audio_price <= 2.99  THEN 'sba_book_tier_299_audio'
            WHEN audio_price <= 4.99  THEN 'sba_book_tier_499_audio'
            WHEN audio_price <= 9.99  THEN 'sba_book_tier_999_audio'
            WHEN audio_price <= 14.99 THEN 'sba_book_tier_1499_audio'
            WHEN audio_price <= 19.99 THEN 'sba_book_tier_1999_audio'
            ELSE 'sba_book_tier_2499_audio'
        END WHERE audio_price IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['price_tier', 'audio_price_tier']);
        });
    }
};
