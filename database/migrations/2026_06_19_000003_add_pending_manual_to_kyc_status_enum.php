<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL implements Laravel enums as check constraints.
        // Drop the existing constraint and recreate it with 'pending_manual' included.
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_kyc_status_check");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kyc_status_check CHECK (kyc_status IN ('pending', 'verified', 'rejected', 'document-required', 'in-review', 'pending_manual'))");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE users DROP CONSTRAINT IF EXISTS users_kyc_status_check");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_kyc_status_check CHECK (kyc_status IN ('pending', 'verified', 'rejected', 'document-required', 'in-review'))");
    }
};
