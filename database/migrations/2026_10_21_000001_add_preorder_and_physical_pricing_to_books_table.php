<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Preorder fields — is_preorder flags the book as a pre-order listing;
            // release_at is when readers gain access / the book auto-publishes.
            $table->boolean('is_preorder')->default(false)->after('preorder_url');
            $table->timestampTz('release_at')->nullable()->after('is_preorder');

            // Per-country physical pricing — JSON map of ISO-3166-1 alpha-2 codes
            // to prices in the book's currency, e.g. {"NG": 4500, "US": 18}.
            // Falls back to physical_price when a buyer's country has no entry.
            $table->json('physical_pricing')->nullable()->after('physical_price');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['is_preorder', 'release_at', 'physical_pricing']);
        });
    }
};
