<?php

namespace App\Console\Commands;

use App\Models\DigitalBookPurchase;
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

        $granted = 0;

        // Stream through preordered purchases in chunks to avoid loading all at once.
        DigitalBookPurchase::where('status', 'preordered')
            ->with(['items.book'])
            ->chunkById(200, function ($purchases) use ($now, &$granted) {
                foreach ($purchases as $purchase) {
                    $allGranted = true;

                    foreach ($purchase->items as $item) {
                        $book = $item->book;

                        if (! $book) {
                            continue;
                        }

                        // Still unreleased — skip this purchase
                        if ($book->is_preorder && $book->release_at && $book->release_at->isFuture()) {
                            $allGranted = false;
                            continue;
                        }

                        // Book is released — grant access
                        $inserted = DB::table('book_user')->insertOrIgnore([
                            'user_id'    => $purchase->user_id,
                            'book_id'    => $item->book_id,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        if ($inserted) {
                            $granted++;
                            Log::info('GrantReleasedPreorders: granted access', [
                                'user_id'    => $purchase->user_id,
                                'book_id'    => $item->book_id,
                                'purchase_id'=> $purchase->id,
                            ]);
                        }
                    }

                    if ($allGranted) {
                        $purchase->update(['status' => 'paid']);
                    }
                }
            });

        $this->info("Granted access for {$granted} preordered book(s).");

        return self::SUCCESS;
    }
}
