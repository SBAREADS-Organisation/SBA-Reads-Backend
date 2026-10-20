<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('data');
            $table->timestamps();

            $table->unique('user_id'); // one draft per author
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_drafts');
    }
};
