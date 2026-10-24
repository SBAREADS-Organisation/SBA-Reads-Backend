<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrantReleasedPreorders extends Command
{
    protected $signature   = 'books:release-preorders';
    protected $description = 'Grant library access for preordered books whose release date has passed.';

    public function handle(): int
    {
        $now = now();

        // Insert all missing book_user rows in one SQL statement.
        // Doing this in PHP (even chunked) OOMs because digital_book_purchases.meta_data
        // holds full payment-provider responses that can be hundreds of KB each.
        $granted = DB::affectingStatement("
            INSERT INTO book_user (user_id, book_id, created_at, updated_at)
            SELECT DISTINCT dbp.user_id, dbpi.book_id, ?::timestamp, ?::timestamp
            FROM digital_book_purchases dbp
            INNER JOIN digital_book_purchase_items dbpi
                ON dbpi.digital_book_purchase_id = dbp.id
            INNER JOIN books
                ON books.id = dbpi.book_id
            WHERE dbp.status = 'preordered'
              AND (
                  books.is_preorder = false
                  OR books.release_at IS NULL
                  OR books.release_at <= ?::timestamp
              )
            ON CONFLICT DO NOTHING
        ", [$now, $now, $now]);

        // Mark purchases as paid when none of their items are still unreleased.
        DB::affectingStatement("
            UPDATE digital_book_purchases
            SET status = 'paid'
            WHERE status = 'preordered'
              AND id NOT IN (
                  SELECT DISTINCT dbp2.id
                  FROM digital_book_purchases dbp2
                  INNER JOIN digital_book_purchase_items dbpi2
                      ON dbpi2.digital_book_purchase_id = dbp2.id
                  INNER JOIN books
                      ON books.id = dbpi2.book_id
                  WHERE dbp2.status = 'preordered'
                    AND books.is_preorder = true
                    AND books.release_at IS NOT NULL
                    AND books.release_at > ?::timestamp
              )
        ", [$now]);

        if ($granted > 0) {
            Log::info("GrantReleasedPreorders: granted access for {$granted} book-user pair(s).");
        }

        $this->info("Granted access for {$granted} preordered book(s).");

        return self::SUCCESS;
    }
}
