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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->decimal('price', 10, 2);
            $table->json('currencies')->default(json_encode([]));
            // $table->jsonb('currency')->default('USD');
            $table->integer('duration_in_days'); // e.g., 30 days
            $table->text('perks')->nullable(); // JSON or simple text
            $table->enum('model', ['monthly', 'yearly']);
            $table->timestampsTz(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
