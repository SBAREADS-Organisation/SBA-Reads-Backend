<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            // null = applies to all books; set = scoped to one book
            $table->unsignedBigInteger('book_id')->nullable();
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->string('code', 64)->unique();
            // 'free' = 100% off; 'percent' = % off; 'fixed' = $ off
            $table->enum('type', ['free', 'percent', 'fixed'])->default('free');
            $table->decimal('value', 8, 2)->default(0); // 0 for free, % or $ amount otherwise
            $table->unsignedInteger('max_uses')->nullable(); // null = unlimited
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_codes');
    }
};
