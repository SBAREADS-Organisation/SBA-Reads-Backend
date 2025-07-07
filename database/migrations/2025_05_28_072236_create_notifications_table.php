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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->nullableMorphs('notifiable'); // Polymorphic relation for different notifiable entities
            $table->string('type'); // Type of notification (e.g Individual, Group, System)
            // Channels used: [email, in-app, push]
            $table->json('channels');
            $table->string('title');
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'read'])->default('pending');
            $table->text('data')->nullable()->default('{}'); // JSON data for notification content
            $table->boolean('read')->default(false); // Read status
            $table->timestampTz('read_at')->nullable(); // Timestamp when the notification was read
            $table->timestampTz('sent_at')->nullable(); // Timestamp when the notification was sent
            $table->timestampsTz();

            $table->index(['user_id', 'title', 'message', 'status', 'notifiable_type', 'notifiable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
