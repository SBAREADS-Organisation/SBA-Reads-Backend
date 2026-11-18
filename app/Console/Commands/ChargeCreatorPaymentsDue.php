<?php

namespace App\Console\Commands;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorPurchase;
use App\Services\Creators\CreatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Charges saved cards for SBA Creators payments that are due:
 *  - memberships set to renew automatically, a day before they end;
 *  - instalments, on their due date (retried two days apart; after too many misses the course locks).
 * Buyers without a saved card are emailed a link to pay instead.
 */
class ChargeCreatorPaymentsDue extends Command
{
    protected $signature = 'creators:charge-due';

    protected $description = 'Renew SBA Creators memberships and collect instalments that are due';

    public function handle(CreatorService $service): int
    {
        $renewed = $this->renewMemberships($service);
        $collected = $this->collectInstalments($service);
        $this->info("Renewals: {$renewed['ok']} renewed, {$renewed['failed']} failed. Instalments: {$collected['ok']} paid, {$collected['failed']} not paid.");

        return self::SUCCESS;
    }

    private function link(CreatorPurchase $purchase): string
    {
        return rtrim((string) config('creators.website_url'), '/') . '/learn/' . $purchase->product_id;
    }

    private function email(CreatorPurchase $purchase, string $subject, string $body): void
    {
        try {
            Mail::to($purchase->buyer->email)->queue(new GenericAppNotification($subject, $body));
        } catch (\Throwable $e) {
            Log::warning("Creator payment email failed for purchase {$purchase->id}: " . $e->getMessage());
        }
    }

    /** @return array{ok: int, failed: int} */
    private function renewMemberships(CreatorService $service): array
    {
        $result = ['ok' => 0, 'failed' => 0];
        $due = CreatorPurchase::with(['product.store', 'buyer', 'card'])
            ->where('status', 'paid')->where('auto_renew', true)->whereNotNull('card_id')->whereNull('renewal_attempted_at')
            ->whereBetween('access_until', [now(), now()->addDay()])
            ->whereHas('product', fn ($q) => $q->where('type', 'membership'))
            // Only each member's latest month renews.
            ->whereNotExists(fn ($later) => $later->from('creator_purchases as later')
                ->whereColumn('later.product_id', 'creator_purchases.product_id')
                ->whereColumn('later.buyer_id', 'creator_purchases.buyer_id')
                ->where('later.status', 'paid')
                ->whereColumn('later.access_until', '>', 'creator_purchases.access_until'))
            ->get();

        foreach ($due as $current) {
            $current->update(['renewal_attempted_at' => now()]);
            $product = $current->product;
            if ($product->status !== 'published' || $product->store->suspended || ! $current->card) {
                $current->update(['auto_renew' => false]);
                $this->email($current, "Your membership of \"{$product->title}\" will not renew",
                    "\"{$product->title}\" is no longer taking members, so your membership won't renew. You keep access until "
                    . $current->access_until->format('j F Y') . '. You have not been charged.');
                continue;
            }

            $quote = $service->quote($product);
            $next = CreatorPurchase::create([
                'product_id' => $product->id, 'store_id' => $product->store_id, 'buyer_id' => $current->buyer_id,
                'currency' => $product->currency, 'status' => 'pending', 'auto_renew' => true, 'card_id' => $current->card_id,
            ] + CreatorService::purchaseFields($quote));

            $ok = $service->chargeSavedCard($current->card, $next, (float) $quote['amount_paid'], 'creator_purchase', $next->id,
                ['product_id' => $product->id, 'purchase_id' => $next->id, 'renewal' => true],
                'SBA Creators: ' . $product->title . ' (1 month, automatic renewal)');

            if ($ok) {
                $result['ok']++;
                continue;
            }
            $result['failed']++;
            $next->delete();
            $current->update(['renewal_failed_at' => now()]);
            $this->email($current, "We couldn't renew your membership of \"{$product->title}\"",
                "We tried to renew your membership of \"{$product->title}\" with your " . $current->card->label() . ", but the payment didn't go through.\n\n"
                . 'Your membership ends on ' . $current->access_until->format('l j F') . ". To keep your access, renew here:\n" . $this->link($current));
        }

        return $result;
    }

    /** @return array{ok: int, failed: int} */
    private function collectInstalments(CreatorService $service): array
    {
        $result = ['ok' => 0, 'failed' => 0];
        $due = CreatorPurchase::with(['product', 'buyer', 'card'])
            ->where('status', 'paid')->where('instalment_status', 'active')
            ->whereNotNull('next_instalment_at')->where('next_instalment_at', '<=', now())
            ->get();
        $maxFailures = (int) config('creators.instalment_max_failures');

        foreach ($due as $purchase) {
            $number = $purchase->instalments_paid + 1;
            $amount = $purchase->instalmentPart((float) $purchase->amount_paid, $number);
            $title = $purchase->product->title;

            if ($purchase->card && $service->chargeSavedCard($purchase->card, $purchase, $amount, 'creator_instalment', $purchase->id,
                ['instalment' => $number], "SBA Creators instalment {$number} of {$purchase->instalment_count}: {$title}")) {
                $result['ok']++;
                continue;
            }

            $result['failed']++;
            $failures = $purchase->instalment_failures + 1;
            $amountText = $purchase->currency . ' ' . number_format($amount, 2);
            if ($failures >= $maxFailures) {
                $purchase->update(['instalment_failures' => $failures, 'instalment_status' => 'defaulted', 'next_instalment_at' => null]);
                $this->email($purchase, "\"{$title}\" is locked until your instalment is paid",
                    "We couldn't collect instalment {$number} of {$purchase->instalment_count} ({$amountText}) for \"{$title}\", so the course is locked for now.\n\n"
                    . "Pay it here and your access comes straight back:\n" . $this->link($purchase));
                continue;
            }
            $purchase->update(['instalment_failures' => $failures, 'next_instalment_at' => now()->addDays(2)]);
            $this->email($purchase, "Instalment {$number} for \"{$title}\" is due",
                ($purchase->card ? "We couldn't charge your " . $purchase->card->label() . " for instalment {$number} of {$purchase->instalment_count} ({$amountText}).\n\n" : "Instalment {$number} of {$purchase->instalment_count} ({$amountText}) for \"{$title}\" is due.\n\n")
                . "Pay it here to keep your access:\n" . $this->link($purchase)
                . ($purchase->card ? "\n\nWe'll try your card again in two days." : ''));
        }

        return $result;
    }
}
