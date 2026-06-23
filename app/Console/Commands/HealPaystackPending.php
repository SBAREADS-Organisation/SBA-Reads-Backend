<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\Paystack\PaystackService;
use App\Services\Paystack\PaystackWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HealPaystackPending extends Command
{
    protected $signature = 'paystack:heal-pending
                            {--dry-run : Show what would be fulfilled without writing anything}
                            {--user=  : Restrict to a single user ID}
                            {--ref=   : Heal a single known Paystack reference}';

    protected $description = 'Verify pending Paystack transactions against Paystack and fulfill confirmed-paid ones';

    public function __construct(
        protected PaystackService $paystackService,
        protected PaystackWebhookService $webhookService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user');
        $singleRef = $this->option('ref');

        if ($dryRun) {
            $this->warn('[DRY RUN] No changes will be written.');
        }

        // If a single reference is given, heal just that one.
        if ($singleRef) {
            return $this->healSingleReference($singleRef, $dryRun);
        }

        // Find all pending Paystack transactions that have a reference buried
        // in their meta_data (the reference column was never populated — that
        // is the root bug this command retroactively repairs).
        $query = Transaction::where('payment_provider', 'paystack')
            ->where('status', 'pending')
            ->whereNotNull('meta_data')
            ->whereIn('purpose_type', ['digital_book_purchase', 'audio_book_purchase']);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $pending = $query->get();

        if ($pending->isEmpty()) {
            $this->info('No pending Paystack transactions found.');
            return self::SUCCESS;
        }

        $this->info("Found {$pending->count()} pending Paystack transaction(s). Verifying with Paystack…");

        $fulfilled = 0;
        $skipped   = 0;
        $failed    = 0;

        foreach ($pending as $txn) {
            $meta = is_string($txn->meta_data)
                ? json_decode($txn->meta_data, true)
                : (array) $txn->meta_data;

            // Extract the reference Paystack returned when payment was initialised.
            $ref = data_get($meta, 'paystack_response.data.reference');

            if (!$ref) {
                $this->line("  [SKIP] txn#{$txn->id} — no reference in meta_data");
                $skipped++;
                continue;
            }

            try {
                $verification = $this->paystackService->verifyPayment($ref);

                if (($verification['data']['status'] ?? '') !== 'success') {
                    $this->line("  [SKIP] txn#{$txn->id} ref={$ref} — Paystack status: " . ($verification['data']['status'] ?? 'unknown'));
                    $skipped++;
                    continue;
                }

                $this->info("  [OK]  txn#{$txn->id} ref={$ref} — verified as success");

                if ($dryRun) {
                    $fulfilled++;
                    continue;
                }

                // Patch the reference column so future lookups work.
                $txn->update(['reference' => $ref]);

                // Replay the webhook — this marks the digital_book_purchase as paid
                // and inserts the book_user row via BookPurchaseService.
                $this->webhookService->handleWebhook([
                    'event' => 'charge.success',
                    'data'  => $verification['data'],
                ]);

                $fulfilled++;
            } catch (\Exception $e) {
                $this->error("  [ERR] txn#{$txn->id} ref={$ref} — {$e->getMessage()}");
                Log::error("paystack:heal-pending txn#{$txn->id}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done — fulfilled: {$fulfilled} | skipped: {$skipped} | errors: {$failed}");

        return self::SUCCESS;
    }

    private function healSingleReference(string $ref, bool $dryRun): int
    {
        $this->info("Verifying reference: {$ref}");

        try {
            $verification = $this->paystackService->verifyPayment($ref);

            if (($verification['data']['status'] ?? '') !== 'success') {
                $this->error("Paystack status: " . ($verification['data']['status'] ?? 'unknown') . " — not fulfilling.");
                return self::FAILURE;
            }

            $this->info("Paystack confirms payment as success.");

            if ($dryRun) {
                $this->warn('[DRY RUN] Would replay webhook — no changes written.');
                return self::SUCCESS;
            }

            // Patch the matching transaction if it exists.
            $txn = Transaction::where('payment_provider', 'paystack')
                ->where(function ($q) use ($ref) {
                    $q->whereRaw("meta_data->'paystack_response'->'data'->>'reference' = ?", [$ref]);
                })->first();

            if ($txn) {
                $txn->update(['reference' => $ref]);
                $this->info("Updated transactions.reference for txn#{$txn->id}.");
            }

            $this->webhookService->handleWebhook([
                'event' => 'charge.success',
                'data'  => $verification['data'],
            ]);

            $this->info("Webhook replayed successfully.");
            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Error: {$e->getMessage()}");
            Log::error("paystack:heal-pending ref={$ref}: {$e->getMessage()}");
            return self::FAILURE;
        }
    }
}
