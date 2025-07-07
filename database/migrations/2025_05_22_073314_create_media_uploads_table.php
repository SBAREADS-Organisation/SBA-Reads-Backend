<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('media_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('context')->index(); // e.g., book_cover, user_avatar
            $table->string('type')->nullable(); // image, video, etc.
            $table->string('folder')->nullable();
            $table->string('public_id')->unique();
            $table->string('url');
            // $table->morphs('mediable'); // Polymorphic relation
            $table->string('mediable_type')->nullable();
            $table->string('mediable_id')->nullable();
            $table->boolean('watermarked')->default(false);
            $table->boolean('is_temporary')->default(false);
            $table->softDeletes();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_uploads');
    }
};
