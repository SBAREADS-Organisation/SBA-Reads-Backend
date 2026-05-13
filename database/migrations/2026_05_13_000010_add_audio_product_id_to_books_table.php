<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('audio_product_id')->nullable()->unique()->after('product_id');
        });

        // Back-fill existing books that already have a product_id.
        DB::table('books')
            ->whereNotNull('product_id')
            ->whereNull('audio_product_id')
            ->orderBy('id')
            ->each(function ($book) {
                DB::table('books')
                    ->where('id', $book->id)
                    ->update(['audio_product_id' => "com.sbareads.audio.{$book->id}"]);
            });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('audio_product_id');
        });
    }
};
