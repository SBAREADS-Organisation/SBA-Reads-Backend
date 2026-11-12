<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Dual-account users (reader + author sharing one email) must be allowed to
    // share a username. The old plain unique(username) constraint fires before
    // the app-layer logic can handle the same-email exception, causing profile
    // saves to fail with a UniqueConstraintViolationException. Replacing it with
    // unique(username, email) lets the same name appear twice — once per account
    // type — while still preventing two unrelated users from claiming the same name.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_unique');
            $table->unique(['username', 'email'], 'users_username_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_username_email_unique');
            $table->unique('username', 'users_username_unique');
        });
    }
};
