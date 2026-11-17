<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SBA Creators marketing: discount codes, affiliates and email lists. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('creator_stores')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('kind', 10); // percent | amount
            $table->decimal('value', 12, 2);
            $table->foreignId('product_id')->nullable()->constrained('creator_products')->cascadeOnDelete();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['store_id', 'code']);
        });

        Schema::create('creator_affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('creator_stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->timestamps();
            $table->unique(['store_id', 'user_id']);
        });

        Schema::create('creator_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('creator_stores')->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('source', 20)->default('signup'); // signup | buyer
            $table->string('token', 40)->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['store_id', 'email']);
        });

        Schema::create('creator_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('creator_stores')->cascadeOnDelete();
            $table->string('subject', 160);
            $table->text('body');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();
        });

        Schema::table('creator_stores', function (Blueprint $table) {
            // Share of the creator's earning paid to affiliates; null means affiliates are off.
            $table->decimal('affiliate_rate', 4, 3)->nullable()->after('suspended');
        });

        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_id')->nullable()->after('ended_notified_at');
            $table->decimal('discount', 12, 2)->default(0)->after('discount_id');
            $table->unsignedBigInteger('affiliate_id')->nullable()->after('discount');
            $table->decimal('affiliate_earning', 12, 2)->default(0)->after('affiliate_id');
        });
    }

    public function down(): void
    {
        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->dropColumn(['discount_id', 'discount', 'affiliate_id', 'affiliate_earning']);
        });
        Schema::table('creator_stores', function (Blueprint $table) {
            $table->dropColumn('affiliate_rate');
        });
        Schema::dropIfExists('creator_broadcasts');
        Schema::dropIfExists('creator_subscribers');
        Schema::dropIfExists('creator_affiliates');
        Schema::dropIfExists('creator_discounts');
    }
};
