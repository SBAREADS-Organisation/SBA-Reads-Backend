<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL CHECK constraints cannot be altered in-place — drop and recreate.
        DB::statement("ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_status_check");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN (
            'pending', 'succeeded', 'failed', 'processing', 'refunded',
            'available', 'requested', 'declined', 'approved', 'sent',
            'settled', 'completed', 'locked', 'withdrawn', 'on_hold',
            'expired', 'iap_pending'
        ))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_status_check");
        DB::statement("ALTER TABLE transactions ADD CONSTRAINT transactions_status_check CHECK (status IN (
            'pending', 'succeeded', 'failed', 'processing', 'refunded',
            'available', 'requested', 'declined', 'approved', 'sent',
            'settled', 'completed', 'locked', 'withdrawn', 'on_hold',
            'expired'
        ))");
    }
};
