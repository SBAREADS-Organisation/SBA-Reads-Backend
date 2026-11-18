<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SBA Creators: saved cards, automatic membership renewal, instalments, upsells and book bundles. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_saved_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Paystack's reusable authorization, stored encrypted. Card numbers never reach SBA Reads.
            $table->text('authorization_code');
            $table->string('signature')->nullable();
            $table->string('email');
            $table->string('last4', 4)->nullable();
            $table->string('brand', 30)->nullable();
            $table->string('exp_month', 2)->nullable();
            $table->string('exp_year', 4)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'signature']);
        });

        Schema::table('creator_products', function (Blueprint $table) {
            $table->unsignedTinyInteger('instalments_max')->nullable()->after('duration_minutes');
            $table->foreignId('upsell_product_id')->nullable()->after('instalments_max')->constrained('creator_products')->nullOnDelete();
            $table->decimal('upsell_price', 12, 2)->nullable()->after('upsell_product_id');
            $table->foreignId('bonus_book_id')->nullable()->after('upsell_price')->constrained('books')->nullOnDelete();
        });

        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->boolean('auto_renew')->default(false)->after('affiliate_earning');
            $table->unsignedBigInteger('card_id')->nullable()->after('auto_renew');
            $table->timestamp('renewal_attempted_at')->nullable()->after('card_id');
            $table->timestamp('renewal_failed_at')->nullable()->after('renewal_attempted_at');
            $table->unsignedBigInteger('parent_purchase_id')->nullable()->after('renewal_failed_at')->index();
            $table->unsignedTinyInteger('instalment_count')->nullable()->after('parent_purchase_id');
            $table->unsignedTinyInteger('instalments_paid')->default(0)->after('instalment_count');
            $table->string('instalment_status', 20)->nullable()->after('instalments_paid'); // active | completed | defaulted
            $table->timestamp('next_instalment_at')->nullable()->after('instalment_status');
            $table->unsignedTinyInteger('instalment_failures')->default(0)->after('next_instalment_at');
        });
    }

    public function down(): void
    {
        Schema::table('creator_purchases', function (Blueprint $table) {
            $table->dropColumn([
                'auto_renew', 'card_id', 'renewal_attempted_at', 'renewal_failed_at', 'parent_purchase_id',
                'instalment_count', 'instalments_paid', 'instalment_status', 'next_instalment_at', 'instalment_failures',
            ]);
        });
        Schema::table('creator_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('upsell_product_id');
            $table->dropConstrainedForeignId('bonus_book_id');
            $table->dropColumn(['instalments_max', 'upsell_price']);
        });
        Schema::dropIfExists('creator_saved_cards');
    }
};
