<?php

namespace App\Services\Creators;

use App\Mail\Generic\GenericAppNotification;
use App\Models\CreatorAffiliate;
use App\Models\CreatorDiscount;
use App\Models\CreatorProduct;
use App\Models\CreatorPurchase;
use App\Models\CreatorSavedCard;
use App\Models\CreatorSlot;
use App\Models\CreatorStore;
use App\Models\CreatorSubscriber;
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
     * A discount code comes off the price first; an affiliate's commission comes out of the creator's earning.
     *
     * @return array{list_price: float, discount: float, discount_id: ?int, price: float, fee: float, amount_paid: float, creator_earning: float, affiliate_id: ?int, affiliate_earning: float, fee_payer: string, plan: string}
     */
    public function quote(CreatorProduct $product, ?CreatorDiscount $discount = null, ?CreatorAffiliate $affiliate = null, ?float $priceOverride = null): array
    {
        $store = $product->store;
        $listPrice = round($priceOverride ?? (float) $product->price, 2);
        $off = $discount ? $discount->amountOff($listPrice) : 0.0;
        $price = round($listPrice - $off, 2);
        $fee = round($price * $store->feeRate($product->currency), 2);
        $buyerPays = $store->fee_payer === 'buyer';
        $earning = $buyerPays ? $price : round($price - $fee, 2);
        $commission = $affiliate && $store->affiliate_rate ? round($earning * (float) $store->affiliate_rate, 2) : 0.0;

        return [
            'list_price' => $listPrice,
            'discount' => $off,
            'discount_id' => $off > 0 ? $discount->id : null,
            'price' => $price,
            'fee' => $fee,
            'amount_paid' => $buyerPays ? round($price + $fee, 2) : $price,
            'creator_earning' => round($earning - $commission, 2),
            'affiliate_id' => $commission > 0 ? $affiliate->id : null,
            'affiliate_earning' => $commission,
            'fee_payer' => $store->fee_payer,
            'plan' => $store->activePlan(),
        ];
    }

    /** The purchase fields a quote fills in. */
    public static function purchaseFields(array $quote): array
    {
        return array_intersect_key($quote, array_flip([
            'price', 'fee', 'amount_paid', 'creator_earning', 'fee_payer', 'plan',
            'discount', 'discount_id', 'affiliate_id', 'affiliate_earning',
        ]));
    }

    /**
     * Marks a purchase paid and credits the creator's wallet. Safe to call more than once.
     * $paystack is the charge data Paystack sent, used to save the card for renewals and instalments.
     */
    public function fulfilPurchase(CreatorPurchase $purchase, array $paystack = []): void
    {
        $credited = DB::transaction(function () use ($purchase, $paystack) {
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
            if ($purchase->isInstalments()) {
                $changes += [
                    'instalments_paid' => 1,
                    'instalment_status' => 'active',
                    'next_instalment_at' => now()->addDays((int) config('creators.instalment_days')),
                ];
            }
            // Keep the card for automatic renewal or later instalments, when Paystack says it can be charged again.
            // A card that can't be saved never stops the purchase: the buyer is just asked to pay by link later.
            if ($purchase->auto_renew || $purchase->isInstalments()) {
                try {
                    if ($card = $this->saveCard($purchase->buyer_id, $paystack)) {
                        $changes['card_id'] = $card->id;
                    }
                } catch (\Throwable $e) {
                    Log::warning('Saving the buyer\'s card failed: ' . $e->getMessage());
                }
            }
            $purchase->update($changes);

            // Instalment buyers pay a share now; the creator and affiliate are paid that share each time.
            $creatorShare = $purchase->isInstalments() ? $purchase->instalmentPart((float) $purchase->creator_earning, 1) : (float) $purchase->creator_earning;
            $affiliateShare = $purchase->isInstalments() ? $purchase->instalmentPart((float) $purchase->affiliate_earning, 1) : (float) $purchase->affiliate_earning;
            $this->payShares($purchase, $creatorShare, $affiliateShare, 'SBA Creators sale: ' . $product->title);

            if ($purchase->discount_id) {
                CreatorDiscount::whereKey($purchase->discount_id)->increment('uses');
            }

            return $purchase->fresh(['product', 'store', 'buyer', 'slot']);
        });

        if (! $credited) {
            return;
        }

        // Upsells added at checkout were paid in the same payment.
        foreach ($credited->addOns()->where('status', 'pending')->get() as $addOn) {
            $this->fulfilPurchase($addOn);
        }

        // A creator can include one of their SBA ebooks; it goes into the buyer's SBA library.
        $book = $credited->product->bonus_book_id ? \App\Models\Book::find($credited->product->bonus_book_id) : null;
        if ($book && $book->status === 'approved') {
            try {
                app(\App\Services\Book\BookPurchaseService::class)->addBooksToUserLibrary($credited->buyer, [$book->id]);
            } catch (\Throwable $e) {
                Log::warning('Adding bonus book failed: ' . $e->getMessage());
            }
        }

        // Buyers join the store's email list; every email they get has an unsubscribe link.
        try {
            CreatorSubscriber::add($credited->store_id, $credited->buyer->email, $credited->buyer->name, 'buyer');
        } catch (\Throwable $e) {
            Log::warning('Adding buyer to creator list failed: ' . $e->getMessage());
        }

        if ($credited->affiliate_id && (float) $credited->affiliate_earning > 0) {
            try {
                $affiliate = CreatorAffiliate::with('user')->find($credited->affiliate_id);
                Mail::to($affiliate->user->email)->queue(new GenericAppNotification(
                    'You earned an affiliate commission',
                    "Someone bought \"{$credited->product->title}\" from {$credited->store->name} through your link.\n\n"
                    . 'You earned ' . $credited->currency . ' ' . number_format((float) $credited->affiliate_earning, 2)
                    . ", which has been added to your SBA Reads wallet."
                ));
            } catch (\Throwable $e) {
                Log::warning('Affiliate commission email failed: ' . $e->getMessage());
            }
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
                $share = $credited->isInstalments() ? $credited->instalmentPart((float) $credited->creator_earning, 1) : (float) $credited->creator_earning;
                $earned = $share > 0
                    ? "\n\nYou earned " . $credited->currency . ' ' . number_format($share, 2) . ", which has been added to your SBA Reads wallet."
                      . ($credited->isInstalments() ? " The buyer is paying in {$credited->instalment_count} monthly instalments; you're paid as each one comes in." : '')
                    : '';
                Mail::to($credited->store->user->email)->queue(new GenericAppNotification(
                    ($product->isCoaching() ? 'New booking: ' : 'New sale on SBA Creators: ') . $product->title,
                    $lines . $earned . "\n\nKeep creating!"
                ));
                $this->notify($credited->store->user, $product->isCoaching() ? 'New booking' : 'New sale', $lines . $earned);
            } catch (\Throwable $e) {
                Log::warning('Creator sale email failed: ' . $e->getMessage());
            }
        }

        $this->notify($credited->buyer, 'You now have access', "\"{$credited->product->title}\" from {$credited->store->name} is ready in My Courses.");
        if ($credited->affiliate_id && (float) $credited->affiliate_earning > 0) {
            $this->notify(CreatorAffiliate::with('user')->find($credited->affiliate_id)?->user, 'You earned a commission',
                "Someone bought \"{$credited->product->title}\" through your link. " . $credited->currency . ' '
                . number_format((float) $credited->affiliate_earning, 2) . ' has been added to your wallet.');
        }
    }

    /** Puts a notification in the person's bell on the website and app. Never blocks a sale. */
    private function notify($user, string $title, string $message): void
    {
        if (! $user) {
            return;
        }
        try {
            app(\App\Services\Notification\NotificationService::class)->send($user, $title, $message, ['in-app']);
        } catch (\Throwable $e) {
            Log::warning('Creator in-app notification failed: ' . $e->getMessage());
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
                . ($purchase->isInstalments()
                    ? 'You paid ' . $purchase->currency . ' ' . number_format($purchase->instalmentPart((float) $purchase->amount_paid, 1), 2)
                      . ", payment 1 of {$purchase->instalment_count}. The next one, " . $purchase->currency . ' '
                      . number_format($purchase->instalmentPart((float) $purchase->amount_paid, 2), 2) . ', is due on '
                      . $purchase->next_instalment_at?->format('j F Y')
                      . ($purchase->card_id ? ' and will be charged to your card automatically' : '') . ".\n\n"
                    : $paid)
                . ($product->bonus_book_id ? "Your bonus ebook is waiting in your SBA Reads library.\n\n" : '')
                . 'Open your ' . ($product->type === 'course' ? 'course' : 'download') . " any time from My Courses on SBA Reads:\n{$link}",
            ],
        };
    }

    /** Credits the creator and any affiliate with their part of a payment. */
    private function payShares(CreatorPurchase $purchase, float $creatorShare, float $affiliateShare, string $description): void
    {
        if ($creatorShare > 0) {
            Transaction::create([
                'id' => (string) Str::uuid(),
                'user_id' => $purchase->store->user_id,
                'reference' => uniqid('crs_'),
                'status' => 'succeeded',
                'currency' => $purchase->currency,
                'amount' => $creatorShare,
                'payment_provider' => 'app',
                'description' => $description,
                'type' => 'payout',
                'direction' => 'credit',
                'purpose_type' => 'creator_sale',
                'purpose_id' => $purchase->id,
            ]);
        }

        // The affiliate who sent the buyer gets their share straight into their wallet.
        $affiliate = $purchase->affiliate_id ? CreatorAffiliate::find($purchase->affiliate_id) : null;
        if ($affiliate && $affiliateShare > 0) {
            Transaction::create([
                'id' => (string) Str::uuid(),
                'user_id' => $affiliate->user_id,
                'reference' => uniqid('cra_'),
                'status' => 'succeeded',
                'currency' => $purchase->currency,
                'amount' => $affiliateShare,
                'payment_provider' => 'app',
                'description' => 'SBA Creators affiliate commission: ' . $purchase->product->title,
                'type' => 'payout',
                'direction' => 'credit',
                'purpose_type' => 'creator_affiliate',
                'purpose_id' => $purchase->id,
            ]);
        }
    }

    /** Saves the card from a Paystack charge when it can be charged again; reuses the same card if already saved. */
    public function saveCard(int $userId, array $paystack): ?CreatorSavedCard
    {
        $auth = $paystack['authorization'] ?? null;
        $email = $paystack['customer']['email'] ?? null;
        if (! is_array($auth) || empty($auth['authorization_code']) || empty($auth['reusable']) || ($auth['channel'] ?? 'card') !== 'card' || ! $email) {
            return null;
        }
        $existing = ! empty($auth['signature'])
            ? CreatorSavedCard::where('user_id', $userId)->where('signature', $auth['signature'])->first()
            : null;
        $fields = [
            'authorization_code' => $auth['authorization_code'], 'email' => $email,
            'last4' => $auth['last4'] ?? null, 'brand' => $auth['card_type'] ?? ($auth['brand'] ?? null),
            'exp_month' => $auth['exp_month'] ?? null, 'exp_year' => $auth['exp_year'] ?? null,
        ];
        if ($existing) {
            $existing->update($fields);

            return $existing;
        }

        return CreatorSavedCard::create($fields + ['user_id' => $userId, 'signature' => $auth['signature'] ?? null]);
    }

    /**
     * Charges a saved card for a creator payment. The Transaction is created first, so the
     * charge.success webhook finds it like any other payment; an immediate success is applied now too.
     */
    public function chargeSavedCard(CreatorSavedCard $card, CreatorPurchase $purchase, float $amount, string $purpose, int $purposeId, array $meta, string $description): bool
    {
        $reference = 'paystack_crc_' . Str::random(20);
        $transaction = Transaction::create([
            'id' => (string) Str::uuid(),
            'user_id' => $purchase->buyer_id,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $purchase->currency,
            'payment_provider' => 'paystack',
            'description' => $description,
            'purpose_type' => $purpose,
            'purpose_id' => $purposeId,
            'meta_data' => $meta,
            'status' => 'pending',
            'type' => 'purchase',
            'direction' => 'debit',
        ]);

        $result = app(\App\Services\Paystack\PaystackService::class)->chargeAuthorization(
            $card->email, $amount, $card->authorization_code, $reference,
            ['purpose' => $purpose, 'purpose_id' => $purposeId], $purchase->currency,
        );
        $data = $result['data'] ?? [];
        if (($result['status'] ?? false) && ($data['status'] ?? null) === 'success') {
            $transaction->update(['status' => 'succeeded']);
            $this->applyCharge($transaction->fresh(), $data);

            return true;
        }
        // "send_otp" and similar mean the bank wants the buyer present; treat as failed and ask them to pay by link.
        $transaction->update(['status' => 'failed', 'meta_data' => $meta + ['charge_response' => $data['gateway_response'] ?? ($result['message'] ?? 'declined')]]);

        return false;
    }

    /** Applies a successful saved-card or link payment, the same way the webhook does. Safe to repeat. */
    public function applyCharge(Transaction $transaction, array $paystack = []): void
    {
        if ($transaction->purpose_type === 'creator_purchase' && ($purchase = CreatorPurchase::find($transaction->purpose_id))) {
            $this->fulfilPurchase($purchase, $paystack);
        }
        if ($transaction->purpose_type === 'creator_instalment') {
            $this->fulfilInstalment($transaction);
        }
    }

    /** Records a later instalment and pays the creator and affiliate their share of it. Safe to repeat. */
    public function fulfilInstalment(Transaction $transaction): void
    {
        $applied = DB::transaction(function () use ($transaction) {
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->first();
            $purchase = CreatorPurchase::whereKey($transaction->purpose_id)->lockForUpdate()->first();
            $number = (int) ($transaction->meta_data['instalment'] ?? 0);
            if (! $purchase || ($transaction->meta_data['instalment_applied'] ?? false) || $number !== $purchase->instalments_paid + 1) {
                return null;
            }

            $done = $number >= $purchase->instalment_count;
            $purchase->update([
                'instalments_paid' => $number,
                'instalment_status' => $done ? 'completed' : 'active',
                'next_instalment_at' => $done ? null : now()->addDays((int) config('creators.instalment_days')),
                'instalment_failures' => 0,
            ]);
            $this->payShares(
                $purchase,
                $purchase->instalmentPart((float) $purchase->creator_earning, $number),
                $purchase->instalmentPart((float) $purchase->affiliate_earning, $number),
                "SBA Creators instalment {$number} of {$purchase->instalment_count}: " . $purchase->product->title,
            );
            $transaction->update(['meta_data' => array_merge((array) $transaction->meta_data, ['instalment_applied' => true])]);

            return [$purchase->fresh(['product', 'buyer']), $number, $done];
        });

        if ($applied) {
            [$purchase, $number, $done] = $applied;
            try {
                Mail::to($purchase->buyer->email)->queue(new GenericAppNotification(
                    $done ? "\"{$purchase->product->title}\" is fully paid" : "Payment {$number} of {$purchase->instalment_count} received",
                    $done
                        ? "Thank you. You've paid all {$purchase->instalment_count} instalments for \"{$purchase->product->title}\". It's yours to keep."
                        : "Thank you. We received payment {$number} of {$purchase->instalment_count} for \"{$purchase->product->title}\".\n\nThe next one is due on " . $purchase->next_instalment_at?->format('j F Y') . '.'
                ));
            } catch (\Throwable $e) {
                Log::warning('Instalment receipt email failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Refunds a purchase: the buyer loses access, the creator's and affiliate's earnings from it are taken back
     * out of their wallets, and a booked coaching time is freed. With $refundPayment, the money is also
     * returned to the buyer through Paystack first; if Paystack refuses, nothing is changed.
     *
     * @return string|null why it couldn't be done, or null when done
     */
    public function refundPurchase(CreatorPurchase $purchase, string $reason, bool $refundPayment): ?string
    {
        if ($purchase->status !== 'paid') {
            return 'Only paid purchases can be refunded.';
        }
        $payments = Transaction::where('payment_provider', 'paystack')->where('status', 'succeeded')
            ->where(fn ($q) => $q->where(fn ($p) => $p->where('purpose_type', 'creator_purchase')->where('purpose_id', $purchase->parent_purchase_id ?? $purchase->id))
                ->orWhere(fn ($p) => $p->where('purpose_type', 'creator_instalment')->where('purpose_id', $purchase->id)))
            ->get();

        if ($refundPayment && (float) $purchase->amount_paid > 0) {
            if ($purchase->parent_purchase_id) {
                return 'This was an add-on paid together with another product. Refund the main purchase instead.';
            }
            if ($payments->isEmpty()) {
                return 'No Paystack payment was found for this purchase. Refund it in Paystack yourself, then remove access without the Paystack refund.';
            }
            $paystack = app(\App\Services\Paystack\PaystackService::class);
            foreach ($payments as $payment) {
                $result = $paystack->refund($payment->reference);
                if (! ($result['status'] ?? false)) {
                    return 'Paystack did not accept the refund: ' . ($result['message'] ?? 'unknown error') . '. Nothing was changed.';
                }
                $payment->update(['status' => 'refunded']);
            }
        }

        $refunded = DB::transaction(function () use ($purchase, $reason) {
            $purchase = CreatorPurchase::whereKey($purchase->id)->lockForUpdate()->first();
            if ($purchase->status !== 'paid') {
                return [];
            }
            // An add-on bought in the same payment goes with its main purchase.
            $all = collect([$purchase])->merge($purchase->addOns()->where('status', 'paid')->get());
            foreach ($all as $item) {
                $item->update(['status' => 'refunded', 'auto_renew' => false, 'instalment_status' => $item->instalment_status ? 'completed' : null, 'next_instalment_at' => null]);
                // Earnings only count while "succeeded", so this takes them back out of the wallets.
                Transaction::whereIn('purpose_type', ['creator_sale', 'creator_affiliate'])->where('purpose_id', $item->id)
                    ->where('status', 'succeeded')->update(['status' => 'refunded']);
                CreatorSlot::where('purchase_id', $item->id)->update(['purchase_id' => null, 'booked_at' => null, 'held_until' => null]);
                if ($item->discount_id) {
                    CreatorDiscount::whereKey($item->discount_id)->where('uses', '>', 0)->decrement('uses');
                }
            }

            return $all->map->fresh(['product', 'buyer', 'store.user'])->all();
        });

        foreach ($refunded as $item) {
            try {
                Mail::to($item->buyer->email)->queue(new GenericAppNotification(
                    "Refund: \"{$item->product->title}\"",
                    "Your purchase of \"{$item->product->title}\" from {$item->store->name} has been refunded, so it's no longer in My Courses.\n\n"
                    . ($reason ? "Reason: {$reason}\n\n" : '')
                    . 'If you paid by card, the money usually reaches your account within 5 to 10 working days.'
                ));
                Mail::to($item->store->user->email)->queue(new GenericAppNotification(
                    "A sale was refunded: \"{$item->product->title}\"",
                    "SBA Reads refunded a purchase of \"{$item->product->title}\" by " . ($item->buyer->name ?: 'a buyer') . ".\n\n"
                    . ($reason ? "Reason: {$reason}\n\n" : '')
                    . 'The earnings from this sale have been taken out of your wallet balance.'
                ));
            } catch (\Throwable $e) {
                Log::warning('Refund email failed: ' . $e->getMessage());
            }
        }

        return null;
    }

    /** Starts a Paystack payment for the next instalment, for buyers paying by link. */
    public function instalmentPaymentLink(CreatorPurchase $purchase): array
    {
        $number = $purchase->instalments_paid + 1;
        $amount = $purchase->instalmentPart((float) $purchase->amount_paid, $number);
        $transaction = app(\App\Services\Payments\PaymentService::class)->createPayment([
            'amount' => $amount,
            'currency' => $purchase->currency,
            'description' => "SBA Creators instalment {$number} of {$purchase->instalment_count}: " . $purchase->product->title,
            'purpose' => 'creator_instalment',
            'purpose_id' => $purchase->id,
            'meta_data' => ['instalment' => $number],
        ], $purchase->buyer);
        if ($transaction instanceof \Illuminate\Http\JsonResponse) {
            throw new \RuntimeException('Payment could not be started.');
        }

        return ['authorization_url' => $transaction->payment_client_secret, 'amount' => $amount, 'instalment' => $number];
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
