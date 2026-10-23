<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: alter enum by renaming the column, adding a new one, then swapping.
        // Simpler: just check the DB driver and alter accordingly.
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE digital_book_purchases ALTER COLUMN status TYPE VARCHAR(32)");
            DB::statement("ALTER TABLE digital_book_purchases ADD CONSTRAINT digital_book_purchases_status_check
                CHECK (status IN ('pending','paid','failed','payout_initiated','payout_completed','payout_failed','preordered'))");
        } else {
            DB::statement("ALTER TABLE digital_book_purchases MODIFY COLUMN status ENUM('pending','paid','failed','payout_initiated','payout_completed','payout_failed','preordered') NOT NULL DEFAULT 'pending'");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE digital_book_purchases DROP CONSTRAINT IF EXISTS digital_book_purchases_status_check");
            DB::statement("ALTER TABLE digital_book_purchases ALTER COLUMN status TYPE VARCHAR(32)");
            DB::statement("ALTER TABLE digital_book_purchases ADD CONSTRAINT digital_book_purchases_status_check
                CHECK (status IN ('pending','paid','failed','payout_initiated','payout_completed','payout_failed'))");
        } else {
            DB::statement("ALTER TABLE digital_book_purchases MODIFY COLUMN status ENUM('pending','paid','failed','payout_initiated','payout_completed','payout_failed') NOT NULL DEFAULT 'pending'");
        }
    }
};
