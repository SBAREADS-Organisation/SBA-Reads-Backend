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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable()->default('NO NAME');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->enum('status', ['active', 'suspended', 'unverified', 'verified', 'pending', 'banned', 'rejected'])->default('active');
            $table->enum('account_type', ['reader', 'author', 'guest', 'superadmin', 'support', 'manager'])->default('guest');
            $table->json('profile_info')->nullable()->default('{}');
            $table->timestamp('last_login_at')->nullable()->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->string('mfa_secret')->nullable()->default('');
            $table->string('password');
            $table->string('default_login');
            $table->string('first_name')->nullable()->default('NO NAME');
            $table->string('last_name')->nullable()->default('NO NAME');
            $table->string('device_token')->nullable();
            // $table->string('account_type (reader/author)');
            $table->rememberToken();
            $table->boolean('deleted')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamp(0)->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            // Indexes
            $table->index('email');
            $table->index('status');
            $table->index('account_type');
        });

        Schema::create('professional_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('company_name')->nullable()->default('');
            $table->string('job_title')->nullable()->default('');
            $table->text('bio')->nullable()->default('');
            $table->string('website')->nullable()->default('');
            // $table->timestamps(0)->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->boolean('deleted')->default(false);
            $table->boolean('archived')->default(false);
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));

            // Indexes
            $table->index('user_id');
            // $table->index('company_name');
        });

        // Schema::create('social_accounts', function (Blueprint $table) {
        //     $table->id();
        //     $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
        //     $table->string('provider');
        //     $table->string('provider_id');
        //     $table->string('access_token');
        //     $table->string('refresh_token');
        //     $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        //     $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP'));

        //     // Indexes
        //     $table->index('user_id');
        //     $table->index('provider');
        // });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('professional_profiles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
