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

    protected $description = 'Insert book_user rows missing for confirmed-paid purchases, including linked accounts';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user');

        $this->info($dryRun ? '[DRY RUN] Scanning…' : 'Healing missing book_user rows…');

        // ── 1. Collect all confirmed paid (user_id, book_id) pairs ──────────

        // Paystack / Stripe purchases recorded via the purchase tables
        $digitalQ = DB::table('digital_book_purchase_items as dbpi')
            ->join('digital_book_purchases as dbp', 'dbp.id', '=', 'dbpi.digital_book_purchase_id')
            ->where('dbp.status', 'paid')
            ->select('dbp.user_id', 'dbpi.book_id');

        if ($userId) $digitalQ->where('dbp.user_id', $userId);

        // IAP / Stripe transactions that store book_ids as a JSON array in meta_data
        // e.g. {"book_ids":["68"], ...}
        $iapArrayQ = DB::table('transactions')
            ->whereIn('status', ['success', 'succeeded'])
            ->whereRaw("meta_data ? 'book_ids'")
            ->selectRaw("user_id, jsonb_array_elements_text(meta_data->'book_ids')::integer AS book_id");

        if ($userId) $iapArrayQ->where('user_id', $userId);

        // Legacy rows that stored a single book_id scalar instead of an array
        $iapScalarQ = DB::table('transactions')
            ->whereIn('status', ['success', 'succeeded'])
            ->whereRaw("meta_data ? 'book_id'")
            ->whereRaw("NOT (meta_data ? 'book_ids')")
            ->selectRaw("user_id, (meta_data->>'book_id')::integer AS book_id");

        if ($userId) $iapScalarQ->where('user_id', $userId);

        // Audio purchases
        $audioQ = DB::table('audio_book_purchases')
            ->where('status', 'paid')
            ->select('user_id', 'book_id');

        if ($userId) $audioQ->where('user_id', $userId);

        // Merge all sources; drop rows where either id is null/0
        $allPaid = $digitalQ->get()
            ->concat($iapArrayQ->get())
            ->concat($iapScalarQ->get())
            ->concat($audioQ->get())
            ->filter(fn ($r) => !empty($r->user_id) && !empty($r->book_id))
            ->unique(fn ($r) => $r->user_id . ':' . $r->book_id)
            ->values();

        if ($allPaid->isEmpty()) {
            $this->info('No paid purchases found.');
            return self::SUCCESS;
        }

        // ── 2. Expand pairs to include linked accounts ───────────────────────
        // A book bought by either side of a reader↔author link is granted to both.
        $purchaserIds = $allPaid->pluck('user_id')->unique()->values()->toArray();

        $links = DB::table('linked_accounts')
            ->whereIn('user_id', $purchaserIds)
            ->orWhereIn('linked_user_id', $purchaserIds)
            ->get(['user_id', 'linked_user_id']);

        // Build map: each purchaser → all account IDs it shares a link with
        $accountMap = array_fill_keys($purchaserIds, []);
        foreach ($purchaserIds as $id) {
            $accountMap[$id][] = $id;
        }
        foreach ($links as $link) {
            if (array_key_exists($link->user_id, $accountMap)) {
                $accountMap[$link->user_id][] = $link->linked_user_id;
            }
            if (array_key_exists($link->linked_user_id, $accountMap)) {
                $accountMap[$link->linked_user_id][] = $link->user_id;
            }
        }

        $expanded = collect();
        foreach ($allPaid as $row) {
            foreach (array_unique($accountMap[$row->user_id] ?? [$row->user_id]) as $accountId) {
                $expanded->push((object) ['user_id' => $accountId, 'book_id' => $row->book_id]);
            }
        }
        $expanded = $expanded->unique(fn ($r) => $r->user_id . ':' . $r->book_id)->values();

        // ── 3. Find which pairs are missing from book_user ──────────────────
        $allUserIds = $expanded->pluck('user_id')->unique()->values()->toArray();

        $existing = DB::table('book_user')
            ->whereIn('user_id', $allUserIds)
            ->get(['user_id', 'book_id'])
            ->mapWithKeys(fn ($r) => [$r->user_id . ':' . $r->book_id => true]);

        $missing = $expanded
            ->filter(fn ($r) => !isset($existing[$r->user_id . ':' . $r->book_id]))
            ->values();

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

        // ── 4. Insert missing rows in batches ────────────────────────────────
        $now     = now();
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
        Log::info('library:heal inserted ' . $inserted . ' missing book_user rows.' . ($userId ? " (user {$userId})" : ''));

        return self::SUCCESS;
    }
}
