<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Withdrawals wait until this time after a payout bank account is changed. */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'payout_hold_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('payout_hold_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'payout_hold_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('payout_hold_until');
            });
        }
    }
};
