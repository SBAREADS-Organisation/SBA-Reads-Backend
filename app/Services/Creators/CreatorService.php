<?php

namespace App\Services\Creators;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSlot;
use App\Models\CreatorStore;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
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
            $product = $purchase->product;

            $changes = ['status' => 'paid', 'paid_at' => now()];
            if ($product->isEvent()) {
                $changes['ticket_code'] = $this->newTicketCode();
            }
            if ($product->isMembership()) {
                // A renewal adds a month after the current one, so paying early loses nothing.
                $current = CreatorPurchase::where('product_id', $product->id)->where('buyer_id', $purchase->buyer_id)
                    ->where('status', 'paid')->max('access_until');
                $from = $current && Carbon::parse($current)->isFuture() ? Carbon::parse($current) : now();
                $changes['access_until'] = $from->copy()->addDays((int) config('creators.plan_days'));
            }
            if ($product->isCoaching() && $purchase->slot_id) {
                $slot = CreatorSlot::whereKey($purchase->slot_id)->lockForUpdate()->first();
                if ($slot && $slot->isOpenFor($purchase)) {
                    $slot->update(['purchase_id' => $purchase->id, 'booked_at' => now(), 'held_until' => null]);
                } else {
                    // Someone else got the time while this payment was finishing; the buyer picks another.
                    $changes['slot_id'] = null;
                }
            }
            $purchase->update($changes);

            if ((float) $purchase->creator_earning > 0) {
                Transaction::create([
                    'id' => (string) Str::uuid(),
                    'user_id' => $purchase->store->user_id,
                    'reference' => uniqid('crs_'),
                    'status' => 'succeeded',
                    'currency' => $purchase->currency,
                    'amount' => $purchase->creator_earning,
                    'payment_provider' => 'app',
                    'description' => 'SBA Creators sale: ' . $product->title,
                    'type' => 'payout',
                    'direction' => 'credit',
                    'purpose_type' => 'creator_sale',
                    'purpose_id' => $purchase->id,
                ]);
            }

            return $purchase->fresh(['product', 'store', 'buyer', 'slot']);
        });

        if (! $credited) {
            return;
        }

        try {
            [$subject, $body] = $this->receipt($credited);
            Mail::to($credited->buyer->email)->queue(new GenericAppNotification($subject, $body));
        } catch (\Throwable $e) {
            Log::warning('Creator purchase receipt email failed: ' . $e->getMessage());
        }

        if ((float) $credited->price > 0 || $credited->product->isCoaching()) {
            try {
                $product = $credited->product;
                $buyer = $credited->buyer?->name ?: 'Someone';
                $lines = match (true) {
                    $product->isCoaching() => "{$buyer} booked \"{$product->title}\""
                        . ($credited->slot ? ' for ' . $this->when($credited->slot->starts_at, $product) : ' and will choose a time soon') . '.',
                    $product->isMembership() => "{$buyer} paid for a month of \"{$product->title}\".",
                    default => "Someone just bought \"{$product->title}\".",
                };
                $earned = (float) $credited->creator_earning > 0
                    ? "\n\nYou earned " . $credited->currency . ' ' . number_format((float) $credited->creator_earning, 2) . ", which has been added to your SBA Reads wallet."
                    : '';
                Mail::to($credited->store->user->email)->queue(new GenericAppNotification(
                    ($product->isCoaching() ? 'New booking: ' : 'New sale on SBA Creators: ') . $product->title,
                    $lines . $earned . "\n\nKeep creating!"
                ));
            } catch (\Throwable $e) {
                Log::warning('Creator sale email failed: ' . $e->getMessage());
            }
        }
    }

    /** Books a time for a paid coaching session that doesn't have one yet. */
    public function bookSlot(CreatorPurchase $purchase, int $slotId): ?string
    {
        $booked = DB::transaction(function () use ($purchase, $slotId) {
            $purchase = CreatorPurchase::whereKey($purchase->id)->lockForUpdate()->first();
            if ($purchase->slot_id) {
                return 'This session already has a time.';
            }
            $slot = CreatorSlot::where('product_id', $purchase->product_id)->whereKey($slotId)->lockForUpdate()->first();
            if (! $slot || ! $slot->isOpenFor($purchase)) {
                return 'That time has just been taken. Please choose another.';
            }
            $slot->update(['purchase_id' => $purchase->id, 'booked_at' => now(), 'held_until' => null]);
            $purchase->update(['slot_id' => $slot->id]);

            return null;
        });
        if ($booked === null) {
            try {
                $purchase = $purchase->fresh(['product', 'store.user', 'buyer', 'slot']);
                $when = $this->when($purchase->slot->starts_at, $purchase->product);
                Mail::to($purchase->buyer->email)->queue(new GenericAppNotification(
                    "Your session is booked: {$purchase->product->title}",
                    "Your session with {$purchase->store->name} is on {$when}.\n\n" . $this->where($purchase->product)
                    . "Open it here:\n" . $this->link($purchase->product)
                ));
                Mail::to($purchase->store->user->email)->queue(new GenericAppNotification(
                    "New booking: {$purchase->product->title}",
                    ($purchase->buyer->name ?: 'A buyer') . " chose {$when} for \"{$purchase->product->title}\"."
                ));
            } catch (\Throwable $e) {
                Log::warning('Creator booking email failed: ' . $e->getMessage());
            }
        }

        return $booked;
    }

    /** The buyer's email for a confirmed purchase: [subject, body]. */
    private function receipt(CreatorPurchase $purchase): array
    {
        $product = $purchase->product;
        $store = $purchase->store->name;
        $link = $this->link($product);
        $paid = (float) $purchase->amount_paid > 0 ? 'You paid ' . $purchase->currency . ' ' . number_format((float) $purchase->amount_paid, 2) . ".\n\n" : '';

        return match ($product->type) {
            'event' => [
                "Your ticket for \"{$product->title}\"",
                "You're going to \"{$product->title}\" by {$store}.\n\n"
                . 'When: ' . $this->when($product->starts_at, $product) . "\n"
                . ($product->location ? "Where: {$product->location}\n" : '')
                . ($product->online_url ? "Joining link: in your ticket on SBA Reads\n" : '')
                . "Ticket code: {$purchase->ticket_code}\n\n"
                . $paid
                . "Show your ticket at the door, or open it here:\n{$link}",
            ],
            'coaching' => $purchase->slot
                ? [
                    "Your session is booked: {$product->title}",
                    "Your session with {$store} is on " . $this->when($purchase->slot->starts_at, $product) . ".\n\n"
                    . $this->where($product) . $paid . "Open it here:\n{$link}",
                ]
                : [
                    "Choose a time for your session: {$product->title}",
                    "Thank you for booking \"{$product->title}\" with {$store}. The time you picked was taken just before your payment finished, so please choose another here:\n{$link}\n\n" . $paid,
                ],
            'membership' => [
                "Welcome to \"{$product->title}\"",
                "You're a member of \"{$product->title}\" by {$store} until "
                . $purchase->access_until?->format('j F Y') . ".\n\n"
                . $paid
                . "Open the members' area any time from My Courses on SBA Reads:\n{$link}\n\n"
                . "Memberships don't renew by themselves. We'll email you a few days before yours ends.",
            ],
            default => [
                "You now have access to \"{$product->title}\"",
                "Thank you for getting \"{$product->title}\" from {$store}.\n\n"
                . $paid
                . 'Open your ' . ($product->type === 'course' ? 'course' : 'download') . " any time from My Courses on SBA Reads:\n{$link}",
            ],
        };
    }

    public function link(CreatorProduct $product): string
    {
        return rtrim((string) config('creators.website_url'), '/') . "/learn/{$product->id}";
    }

    /** "Saturday 6 December 2026, 10:00am WAT" in the product's own time zone. */
    public function when(?\DateTimeInterface $at, CreatorProduct $product): string
    {
        return $at ? Carbon::instance($at)->setTimezone($product->timezone ?: 'Africa/Lagos')->format('l j F Y, g:ia T') : '';
    }

    private function where(CreatorProduct $product): string
    {
        return ($product->location ? "Where: {$product->location}\n" : '')
            . ($product->online_url ? "Joining link: in your booking on SBA Reads\n" : '')
            . ($product->location || $product->online_url ? "\n" : '');
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
