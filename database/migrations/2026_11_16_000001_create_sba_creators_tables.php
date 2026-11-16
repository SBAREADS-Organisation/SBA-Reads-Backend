<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->string('logo_url')->nullable();
            // Who pays the SBA fee on a sale: the creator (taken from earnings) or the buyer (added at checkout).
            $table->string('fee_payer')->default('creator');
            $table->string('plan')->default('starter');
            $table->timestamp('plan_paid_until')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('suspended')->default(false);
            $table->timestamps();
        });

        Schema::create('creator_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('creator_stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // course | download
            $table->string('title');
            $table->string('slug');
            $table->text('summary')->nullable();
            $table->text('description')->nullable();
            $table->string('cover_url')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('draft'); // draft | published | hidden
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'slug']);
            $table->index(['status', 'published_at']);
        });

        Schema::create('creator_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('creator_products')->cascadeOnDelete();
            $table->string('title');
            $table->string('kind'); // video | pdf | text | file
            $table->unsignedInteger('position')->default(0);
            $table->string('file_key')->nullable();
            $table->string('file_name')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_preview')->default(false);
            $table->timestamps();
            $table->index(['product_id', 'position']);
        });

        Schema::create('creator_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('creator_products')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('creator_stores')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->decimal('fee', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2);
            $table->decimal('creator_earning', 12, 2);
            $table->string('fee_payer');
            $table->string('plan');
            $table->string('currency', 3)->default('NGN');
            $table->string('status')->default('pending'); // pending | paid
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['buyer_id', 'status']);
            $table->index(['store_id', 'status']);
        });

        Schema::create('creator_lesson_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('creator_lessons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->timestamps();
            $table->unique(['lesson_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_lesson_progress');
        Schema::dropIfExists('creator_purchases');
        Schema::dropIfExists('creator_lessons');
        Schema::dropIfExists('creator_products');
        Schema::dropIfExists('creator_stores');
    }
};
