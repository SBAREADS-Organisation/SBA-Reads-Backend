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
        Schema::create('book_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->onDelete('cascade');
            $table->foreignId('admin_id')->constrained('users')->onDelete('cascade'); // reviewer
            $table->enum('action', ['submitted', 'requested_changes', 'approved', 'declined', 'draft', 'rejected'])->default('requested_changes');
            $table->text('note')->nullable(); // Reason or comment from admin
            $table->timestampTz('acted_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            // $table->timestamps();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_audits');
    }
};
