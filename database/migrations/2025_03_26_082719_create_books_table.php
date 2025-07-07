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
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title')->index();
            $table->string('sub_title')->index();
            // slug should be the title to lowercase with hyphens
            $table->string('slug')->index();
            $table->foreignId('author_id')->constrained('users')->onDelete('cascade');
            $table->text('description');
            $table->text('isbn')->index();
            $table->jsonb('table_of_contents');
            $table->jsonb('tags')->nullable()->default('[]');
            $table->jsonb('category')->nullable()->default('[]');
            $table->jsonb('genres')->nullable()->default('[]');
            $table->timestamp('publication_date')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->jsonb('language')->nullable()->default('[]');
            $table->json('cover_image')->nullable()->default('{}');
            $table->string('format')->nullable()->default('');
            $table->jsonb('files')->nullable()->default('[]');
            $table->jsonb('target_audience')->nullable()->default('[]');
            $table->jsonb('pricing')->nullable()->default('{}');
            $table->float('actual_price')->nullable()->default(0.0);
            $table->float('discounted_price')->nullable()->default(0.0);
            $table->string('currency')->nullable()->default('USD');
            $table->string('availability')->nullable()->default('{}');
            $table->integer('views_count')->default(0);
            // store file size file_size
            $table->string('file_size')->nullable()->default('');
            $table->boolean('archived')->default(false);
            $table->boolean('deleted')->default(false);
            // Store drm information drm_info
            $table->jsonb('drm_info')->nullable();
            // Store extended information meta_data
            $table->jsonb('meta_data')->nullable()->default('{}');
            $table->string('publisher')->nullable()->default('');
            $table->softDeletes(); // soft-delete functionality
            $table->timestamp(0)->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
