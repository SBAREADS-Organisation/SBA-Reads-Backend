<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop the single-column unique constraint on email
            $table->dropUnique('users_email_unique');

            // Allow same email across different account types (reader + author)
            $table->unique(['email', 'account_type'], 'users_email_account_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_account_type_unique');
            $table->unique('email', 'users_email_unique');
        });
    }
};
