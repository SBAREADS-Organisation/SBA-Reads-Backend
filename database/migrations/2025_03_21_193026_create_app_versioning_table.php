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
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('app_id')->index();
            $table->enum('platform', ['android', 'ios', 'web', 'all'])->index();
            $table->string('version')->index();
            $table->boolean('force_update')->default(false);
            $table->boolean('deprecated')->default(false);
            $table->timestamp('support_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
