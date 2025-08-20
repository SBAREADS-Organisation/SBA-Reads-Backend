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
        Schema::create('payment_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->string('transaction_reference')->index();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('authors_pay', 15, 2);
            $table->decimal('company_pay', 15, 2);
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->string('currency', 10)->default('usd');
            $table->enum('payment_status', ['pending', 'succeeded', 'failed', 'refunded', 'processing']);
            $table->timestamp('processed_at')->nullable();
            $table->json('audit_metadata')->nullable();
            $table->timestampsTz();

            $table->index(['transaction_reference', 'processed_at']);
            $table->index(['payment_status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_audits');
    }
};
