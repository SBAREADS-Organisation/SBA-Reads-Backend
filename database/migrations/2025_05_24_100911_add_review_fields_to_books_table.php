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
        Schema::table('books', function (Blueprint $table) {
            $table->enum('visibility', ['private', 'public'])->default('private');
            $table->unsignedBigInteger('views')->default(0);
            $table->enum('status', ['pending', 'approved', 'declined', 'needs_changes', 'review', 'expired'])->default('pending');
            // $table->timestamp('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestampTz('expired_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    // public function down(): void
    // {
    //     Schema::table('books', function (Blueprint $table) {
    //         //
    //     });
    // }
};
