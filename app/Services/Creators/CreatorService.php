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

            $purchase->update(['status' => 'paid', 'paid_at' => now()]
                + ($purchase->product->isEvent() ? ['ticket_code' => $this->newTicketCode()] : []));

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

        if ($credited) {
            try {
                $product = $credited->product;
                $link = rtrim((string) config('creators.website_url'), '/') . "/learn/{$product->id}";
                $paid = (float) $credited->amount_paid > 0 ? 'You paid ' . $credited->currency . ' ' . number_format((float) $credited->amount_paid, 2) . ".\n\n" : '';
                if ($product->isEvent()) {
                    $when = $product->starts_at?->copy()->setTimezone($product->timezone ?: 'Africa/Lagos')->format('l j F Y, g:ia T');
                    Mail::to($credited->buyer->email)->queue(new GenericAppNotification(
                        "Your ticket for \"{$product->title}\"",
                        "You're going to \"{$product->title}\" by {$credited->store->name}.\n\n"
                        . "When: {$when}\n"
                        . ($product->location ? "Where: {$product->location}\n" : '')
                        . ($product->online_url ? "Joining link: in your ticket on SBA Reads\n" : '')
                        . "Ticket code: {$credited->ticket_code}\n\n"
                        . $paid
                        . "Show your ticket at the door, or open it here:\n{$link}"
                    ));
                } else {
                    $what = $product->type === 'course' ? 'course' : 'download';
                    Mail::to($credited->buyer->email)->queue(new GenericAppNotification(
                        "You now have access to \"{$product->title}\"",
                        "Thank you for getting \"{$product->title}\" from {$credited->store->name}.\n\n"
                        . $paid
                        . "Open your {$what} any time from My Courses on SBA Reads:\n{$link}"
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('Creator purchase receipt email failed: ' . $e->getMessage());
            }
        }

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

    /** A short code that's easy to read out at the door: no 0/O or 1/I. */
    private function newTicketCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (CreatorPurchase::where('ticket_code', $code)->exists());

        return $code;
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

            // Paying for the plan the free trial already gives starts after the trial, so no free days are lost.
            $from = match (true) {
                $store->plan === $plan && $store->plan_paid_until?->isFuture() => $store->plan_paid_until,
                $plan === config('creators.trial_plan') && $store->onTrial() => $store->trial_ends_at,
                default => now(),
            };
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
