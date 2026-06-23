<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HealBookLibrary extends Command
{
    protected $signature = 'library:heal
                            {--dry-run : Preview missing rows without writing anything}
                            {--user= : Restrict to a single user ID}';

    protected $description = 'Insert book_user rows that are missing for all confirmed-paid purchases';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user');

        $this->info($dryRun ? '[DRY RUN] Scanning…' : 'Healing missing book_user rows…');

        // ── 1. Collect all confirmed paid (user_id, book_id) pairs ──────────

        // Paystack / Stripe digital purchases
        $digitalQ = DB::table('digital_book_purchase_items as dbpi')
            ->join('digital_book_purchases as dbp', 'dbp.id', '=', 'dbpi.digital_book_purchase_id')
            ->where('dbp.status', 'paid')
            ->select('dbp.user_id', 'dbpi.book_id');

        if ($userId) $digitalQ->where('dbp.user_id', $userId);

        // Apple IAP (book_id stored in JSON meta_data column)
        $iapQ = DB::table('transactions')
            ->where('payment_provider', 'apple')
            ->where('status', 'success')
            ->whereRaw("meta_data->>'book_id' IS NOT NULL")
            ->selectRaw("user_id, (meta_data->>'book_id')::integer AS book_id");

        if ($userId) $iapQ->where('user_id', $userId);

        // Audio purchases
        $audioQ = DB::table('audio_book_purchases')
            ->where('status', 'paid')
            ->select('user_id', 'book_id');

        if ($userId) $audioQ->where('user_id', $userId);

        // Merge all three sources into unique (user_id, book_id) pairs
        $allPaid = $digitalQ->get()
            ->concat($iapQ->get())
            ->concat($audioQ->get())
            ->unique(fn ($r) => $r->user_id . ':' . $r->book_id)
            ->values();

        if ($allPaid->isEmpty()) {
            $this->info('No paid purchases found.');
            return self::SUCCESS;
        }

        // ── 2. Find which pairs are missing from book_user ──────────────────

        // Load existing book_user rows for the affected users
        $affectedUserIds = $allPaid->pluck('user_id')->unique()->values()->toArray();

        $existing = DB::table('book_user')
            ->whereIn('user_id', $affectedUserIds)
            ->get(['user_id', 'book_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id . ':' . $r->book_id => true]);

        $missing = $allPaid->filter(
            fn ($r) => !isset($existing[$r->user_id . ':' . $r->book_id])
        )->values();

        if ($missing->isEmpty()) {
            $this->info('Library is already consistent — no missing rows.');
            return self::SUCCESS;
        }

        $this->warn("Found {$missing->count()} missing book_user row(s).");

        if ($dryRun) {
            $this->table(
                ['user_id', 'book_id'],
                $missing->map(fn ($r) => ['user_id' => $r->user_id, 'book_id' => $r->book_id])->toArray()
            );
            return self::SUCCESS;
        }

        // ── 3. Insert missing rows in batches ────────────────────────────────
        $now = now();
        $inserted = 0;

        foreach ($missing->chunk(500) as $chunk) {
            $rows = $chunk->map(fn ($r) => [
                'user_id'    => $r->user_id,
                'book_id'    => $r->book_id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->toArray();

            DB::table('book_user')->insertOrIgnore($rows);
            $inserted += count($rows);
        }

        $this->info("Done — inserted {$inserted} row(s).");
        Log::info("library:heal added {$inserted} missing book_user rows." . ($userId ? " (user {$userId})" : ''));

        return self::SUCCESS;
    }
}
