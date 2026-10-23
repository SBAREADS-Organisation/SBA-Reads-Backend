<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Drop the old constraint (whichever name it was created with) then add the correct one.
            DB::statement("ALTER TABLE digital_book_purchases DROP CONSTRAINT IF EXISTS digital_book_purchases_status_check");
            DB::statement("ALTER TABLE digital_book_purchases ADD CONSTRAINT digital_book_purchases_status_check
                CHECK (status IN ('pending','paid','failed','payout_initiated','payout_completed','payout_failed','preordered'))");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE digital_book_purchases DROP CONSTRAINT IF EXISTS digital_book_purchases_status_check");
            DB::statement("ALTER TABLE digital_book_purchases ADD CONSTRAINT digital_book_purchases_status_check
                CHECK (status IN ('pending','paid','failed','payout_initiated','payout_completed','payout_failed'))");
        }
    }
};
