<?php

namespace App\Services\Creators;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorStore;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreatorService
{
    /**
     * What a buyer pays and what the creator keeps. The fee is a share of the price set by the
     * store's current plan; the store decides whether the buyer pays it on top or it comes out of earnings.
     *
     * @return array{price: float, fee: float, amount_paid: float, creator_earning: float, fee_payer: string, plan: string}
     */
    public function quote(CreatorProduct $product): array
    {
        $store = $product->store;
        $price = round((float) $product->price, 2);
        $fee = round($price * $store->feeRate($product->currency), 2);
        $buyerPays = $store->fee_payer === 'buyer';

        return [
            'price' => $price,
            'fee' => $fee,
            'amount_paid' => $buyerPays ? round($price + $fee, 2) : $price,
            'creator_earning' => $buyerPays ? $price : round($price - $fee, 2),
            'fee_payer' => $store->fee_payer,
            'plan' => $store->activePlan(),
        ];
    }

    /** Marks a purchase paid and credits the creator's wallet. Safe to call more than once. */
    public function fulfilPurchase(CreatorPurchase $purchase): void
    {
        $credited = DB::transaction(function () use ($purchase) {
            $purchase = CreatorPurchase::whereKey($purchase->id)->lockForUpdate()->first();
            if (! $purchase || $purchase->status === 'paid') {
                return null;
            }

            $purchase->update(['status' => 'paid', 'paid_at' => now()]);

            if ((float) $purchase->creator_earning > 0) {
                Transaction::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $purchase->store->user_id,
                    'reference' => uniqid('crs_'),
                    'status' => 'succeeded',
                    'currency' => $purchase->currency,
                    'amount' => $purchase->creator_earning,
                    'payment_provider' => 'app',
                    'description' => 'SBA Creators sale: ' . $purchase->product->title,
                    'type' => 'payout',
                    'direction' => 'credit',
                    'purpose_type' => 'creator_sale',
                    'purpose_id' => $purchase->id,
                ]);
            }

            return $purchase;
        });

        if ($credited && (float) $credited->price > 0) {
            try {
                $creator = $credited->store->user;
                Mail::to($creator->email)->queue(new GenericAppNotification(
                    'New sale on SBA Creators: ' . $credited->product->title,
                    "Someone just bought \"{$credited->product->title}\".\n\n"
                    . 'You earned ' . $credited->currency . ' ' . number_format((float) $credited->creator_earning, 2)
                    . ", which has been added to your SBA Reads wallet.\n\n"
                    . 'Keep creating!'
                ));
            } catch (\Throwable $e) {
                Log::warning('Creator sale email failed: ' . $e->getMessage());
            }
        }
    }

    /** Extends a store's paid plan by one period from whichever is later: now, or its current end. */
    public function fulfilPlan(CreatorStore $store, string $plan, string $reference): void
    {
        DB::transaction(function () use ($store, $plan, $reference) {
            $store = CreatorStore::whereKey($store->id)->lockForUpdate()->first();
            // One period per payment, even if the payment provider reports it twice.
            $meta = Transaction::where('reference', $reference)->first();
            if ($meta && ($meta->meta_data['plan_applied'] ?? false)) {
                return;
            }

            $from = $store->plan === $plan && $store->plan_paid_until && $store->plan_paid_until->isFuture()
                ? $store->plan_paid_until
                : now();
            $store->update([
                'plan' => $plan,
                'plan_paid_until' => $from->copy()->addDays((int) config('creators.plan_days')),
            ]);

            if ($meta) {
                $meta->update(['meta_data' => array_merge((array) $meta->meta_data, ['plan_applied' => true])]);
            }
        });
    }

    public function fileUrl(?string $key, int $minutes = 120, ?string $downloadName = null): ?string
    {
        if (! $key) {
            return null;
        }

        $options = $downloadName
            ? ['ResponseContentDisposition' => 'attachment; filename="' . addcslashes($downloadName, '"\\') . '"']
            : [];

        try {
            return Storage::disk('s3')->temporaryUrl($key, now()->addMinutes($minutes), $options);
        } catch (\Throwable $e) {
            Log::warning('Creator file link failed: ' . $e->getMessage());

            return null;
        }
    }
}
