<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('book_meta_data_analytics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->onDelete('cascade');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('downloads')->default(0);
            $table->unsignedBigInteger('purchases')->default(0);
            $table->unsignedInteger('favourites')->default(0);
            $table->unsignedInteger('bookmarks')->default(0);
            $table->unsignedInteger('reads')->default(0);
            $table->unsignedInteger('shares')->default(0);
            $table->unsignedInteger('likes')->default(0);
            $table->timestamp(0)->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            $table->unique('book_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_meta_data_analytics');
    }
};
