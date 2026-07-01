<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // When false the book is hidden from iOS clients (x-platform: ios)
            // but remains visible on Android (Paystack / Stripe). Defaults to
            // true so every existing book keeps its current visibility.
            $table->boolean('ios_available')->default(true)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('ios_available');
        });
    }
};
