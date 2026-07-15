<?php

namespace App\Services\Stripe;

use App\Jobs\ProcessAuthorPayout;
use App\Mail\Books\BookPurchaseConfirmation;
use App\Mail\Books\BookSaleNotification;
use App\Models\BookMetaDataAnalytics;
use App\Models\AudioBookPurchase;
use App\Models\DigitalBookPurchase;
use App\Models\StripePayout;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Payments\PaymentService;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\PaymentIntent;
use Stripe\Payout;

class StripeWebhookService
{
    use ApiResponse;

    protected PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle the payment intent succeeded event.
     */
    public function handlePaymentIntentSucceeded(PaymentIntent $paymentIntent): void
    {
        try {

            $metadata = $paymentIntent->metadata;

            $transaction = Transaction::where('reference', $metadata->reference)->first();

            if (!$transaction) {
                Log::warning('StripeWebhookService: no transaction found for reference', [
                    'reference' => $metadata->reference ?? null,
                ]);
                return;
            }

            $user = $transaction->user;

            if (!$user) {
                Log::warning('StripeWebhookService: transaction has no associated user', [
                    'transaction_id' => $transaction->id,
                ]);
                return;
            }

            $transaction->update(['status' => 'succeeded']);
            $transaction->refresh();

            // Get transaction purpose
            $purpose = $transaction->purpose_type;

            switch ($purpose) {
                case 'digital_book_purchase':
                    $this->processSuccessfulDigitalBookPurchase($transaction, $user);
                    break;

                case 'audio_book_purchase':
                    $this->processSuccessfulAudioBookPurchase($transaction, $user);
                    break;

                case 'order':
                    $this->processSuccessfulOrder($transaction, $user);
                    break;

                default:
                    break;
            }
        } catch (\Exception $e) {
            Log::error('StripeWebhookService::handlePaymentIntentSucceeded failed', [
                'message'   => $e->getMessage(),
                'reference' => $paymentIntent->metadata->reference ?? null,
            ]);
        }
    }

    /**
     * Processes a successful audio book purchase — marks status paid, credits author 30%.
     */
    protected function processSuccessfulAudioBookPurchase(Transaction $transaction, User $user): void
    {
        try {
            $purchase = AudioBookPurchase::with('book.author')
                ->where('user_id', $user->id)
                ->findOrFail($transaction->purpose_id);

            // Track whether this is the first time we're processing this purchase so we
            // can guard analytics and the author payout against Stripe webhook retries.
            $wasAlreadyPaid = $purchase->status === 'paid';

            if (!$wasAlreadyPaid) {
                $purchase->update(['status' => 'paid']);
            }

            // addBooksToUserLibrary is idempotent — safe to call on every retry.
            app(\App\Services\Book\BookPurchaseService::class)
                ->addBooksToUserLibrary($user, [$purchase->book_id]);

            $author = $purchase->book->author;
            if ($author) {
                // Guard against Stripe webhook retries double-crediting the author.
                // We check for an existing payout transaction rather than relying solely
                // on the purchase status because the status update and the payout write
                // are not atomic — a crash between the two would leave the status 'paid'
                // but the author uncredited, and we need to be able to recover cleanly.
                $payoutAlreadyCreated = Transaction::where('user_id', $author->id)
                    ->where('purpose_type', 'audio_book_purchase')
                    ->where('purpose_id', $purchase->id)
                    ->where('type', 'payout')
                    ->exists();

                if (!$payoutAlreadyCreated) {
                    $author->increment('wallet_balance', $purchase->author_payout_amount);

                    Transaction::create([
                        'id'               => \Illuminate\Support\Str::uuid(),
                        'user_id'          => $author->id,
                        'reference'        => uniqid('audio_pay_'),
                        'status'           => 'succeeded',
                        'currency'         => $purchase->currency ?? 'USD',
                        'amount'           => $purchase->author_payout_amount,
                        'payment_provider' => 'app',
                        'description'      => "Audio book payout for AudioBookPurchase ID: {$purchase->id}",
                        'type'             => 'payout',
                        'direction'        => 'credit',
                        'purpose_type'     => 'audio_book_purchase',
                        'purpose_id'       => $purchase->id,
                    ]);

                    // Notify author of the sale
                    try {
                        $formattedPayout = $this->formatAmount($purchase->author_payout_amount, $purchase->currency ?? 'USD');
                        app(NotificationService::class)->send(
                            $author,
                            'New Audio Book Sale',
                            "Your audio book '{$purchase->book->title}' was purchased. {$formattedPayout} has been added to your wallet.",
                            ['in-app', 'push', 'email'],
                            $purchase,
                            new BookSaleNotification(
                                authorName: $author->first_name ?? $author->name ?? 'Author',
                                bookTitle:  $purchase->book->title ?? 'your book',
                                buyerName:  $user->username ?? $user->name ?? 'A reader',
                                amount:     $formattedPayout,
                                bookType:   'audio',
                            )
                        );
                    } catch (\Exception $e) {
                        Log::warning('Failed to send author audio sale notification', [
                            'author_id' => $author->id,
                            'error'     => $e->getMessage(),
                        ]);
                    }
                }
            }

            if (!$wasAlreadyPaid) {
                $bookAnalytics = \App\Models\BookMetaDataAnalytics::firstOrCreate(
                    ['book_id' => $purchase->book_id],
                    ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                );
                $bookAnalytics->increment('purchases', 1);
                $bookAnalytics->save();

                // Notify reader of successful purchase
                try {
                    app(NotificationService::class)->send(
                        $user,
                        'Purchase Confirmed',
                        "'{$purchase->book->title}' audio book is now in your library. Enjoy listening!",
                        ['in-app', 'push', 'email'],
                        $purchase,
                        new BookPurchaseConfirmation(
                            readerName: $user->first_name ?? $user->name ?? 'Reader',
                            bookTitles: [$purchase->book->title ?? 'your audio book'],
                            amount:     $this->formatAmount($purchase->price, $purchase->currency ?? 'USD'),
                            bookType:   'audio',
                        )
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to send reader audio purchase notification', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Processes a successful digital book purchase transaction.
     *
     * @param Transaction $transaction The local transaction record
     * @param User $user The user associated with the transaction
     *
     * @throws \Exception If processing fails.
     */
    protected function processSuccessfulDigitalBookPurchase(Transaction $transaction, User $user): void
    {
        try {
            $purchaseId = $transaction->purpose_id;
            $purchase = DigitalBookPurchase::with(['items.book.author'])
                ->where('user_id', $user->id)
                ->findOrFail($purchaseId);

            $wasAlreadyPaid = $purchase->status === 'paid';

            if (!$wasAlreadyPaid) {
                $purchase->update(['status' => 'paid']);
            }

            foreach ($purchase->items as $item) {
                // addBooksToUserLibrary is idempotent — safe on every retry.
                app(\App\Services\Book\BookPurchaseService::class)
                    ->addBooksToUserLibrary($user, [$item->book_id]);

                $author = $item->book->author;
                if ($author) {
                    // Per-author idempotency guard: purpose_id is the purchase ID so a
                    // multi-item purchase with the same author still produces one credit.
                    $payoutAlreadyCreated = Transaction::where('user_id', $author->id)
                        ->where('purpose_type', 'digital_book_purchase')
                        ->where('purpose_id', $purchase->id)
                        ->where('type', 'payout')
                        ->exists();

                    if (!$payoutAlreadyCreated) {
                        $author->increment('wallet_balance', $item->author_payout_amount);

                        Transaction::create([
                            'id'           => \Illuminate\Support\Str::uuid(),
                            'user_id'      => $author->id,
                            'reference'    => uniqid('pay_immediate_'),
                            'status'       => 'succeeded',
                            'currency'     => $purchase->currency ?? 'USD',
                            'amount'       => $item->author_payout_amount,
                            'payment_provider' => 'app',
                            'description'  => "Immediate author payout for DigitalBookPurchase ID: {$purchase->id}",
                            'type'         => 'payout',
                            'direction'    => 'credit',
                            'purpose_type' => 'digital_book_purchase',
                            'purpose_id'   => $purchase->id,
                        ]);

                        // Notify author of the sale
                        try {
                            $formattedPayout = $this->formatAmount($item->author_payout_amount, $purchase->currency ?? 'USD');
                            app(NotificationService::class)->send(
                                $author,
                                'New Book Sale',
                                "Your book '{$item->book->title}' was purchased. {$formattedPayout} has been added to your wallet.",
                                ['in-app', 'push', 'email'],
                                $purchase,
                                new BookSaleNotification(
                                    authorName: $author->first_name ?? $author->name ?? 'Author',
                                    bookTitle:  $item->book->title ?? 'your book',
                                    buyerName:  $user->username ?? $user->name ?? 'A reader',
                                    amount:     $formattedPayout,
                                    bookType:   'digital',
                                )
                            );
                        } catch (\Exception $e) {
                            Log::warning('Failed to send author digital sale notification', [
                                'author_id' => $author->id,
                                'error'     => $e->getMessage(),
                            ]);
                        }
                    }
                }

                if (!$wasAlreadyPaid) {
                    $bookAnalytics = BookMetaDataAnalytics::firstOrCreate(
                        ['book_id' => $item->book_id],
                        ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                    );
                    $bookAnalytics->increment('purchases', 1);
                    $bookAnalytics->save();
                }
            }

            // Notify reader once for the entire purchase (only on first processing)
            if (!$wasAlreadyPaid) {
                try {
                    $bookTitles = $purchase->items->pluck('book.title')->filter()->values()->toArray();
                    $titleCount = count($bookTitles);
                    $message    = $titleCount === 1
                        ? "'{$bookTitles[0]}' is now in your library. Enjoy your read!"
                        : "{$titleCount} books are now in your library. Enjoy your reads!";
                    app(NotificationService::class)->send(
                        $user,
                        'Purchase Confirmed',
                        $message,
                        ['in-app', 'push', 'email'],
                        $purchase,
                        new BookPurchaseConfirmation(
                            readerName: $user->first_name ?? $user->name ?? 'Reader',
                            bookTitles: $bookTitles ?: ['your book'],
                            amount:     $this->formatAmount($purchase->total_amount, $purchase->currency ?? 'USD'),
                            bookType:   'digital',
                        )
                    );
                } catch (\Exception $e) {
                    Log::warning('Failed to send reader digital purchase notification', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * @throws \Exception
     */
    protected function processSuccessfulOrder(Transaction $transaction, User $user): void
    {
        try {
            $txnMetaData = $transaction->meta_data; // Already cast as array in model
            $orderId = $transaction->purpose_id;
            if (!is_numeric($orderId)) {
                throw new \InvalidArgumentException('Purpose ID is missing or invalid for book order.');
            }
            $orderId = (int)$orderId;
            $order = $user->orders()->with('items.book.author')->findOrFail($orderId);

            $wasAlreadyPaid = $order->status !== 'pending';

            if ($order->status === 'pending') {
                $order->update(['status' => 'paid']);

                foreach ($order->items as $item) {
                    // Update analytics
                    $bookAnalytics = BookMetaDataAnalytics::firstOrCreate(
                        ['book_id' => $item->book_id],
                        ['purchases' => 0, 'views' => 0, 'downloads' => 0, 'favourites' => 0, 'bookmarks' => 0, 'reads' => 0, 'shares' => 0, 'likes' => 0]
                    );
                    $bookAnalytics->increment('purchases', (int) $item->quantity);
                    $bookAnalytics->save();

                    // Immediately update author wallet balance
                    $author = $item->book->author;
                    if ($author && isset($item->author_payout_amount)) {
                        $authorPayoutAmount = $item->author_payout_amount * $item->quantity;
                        $author->increment('wallet_balance', $authorPayoutAmount);

                        // Create immediate payout transaction record
                        Transaction::create([
                            'id' => \Illuminate\Support\Str::uuid(),
                            'user_id' => $author->id,
                            'reference' => uniqid('pay_immediate_'),
                            'status' => 'succeeded',
                            'currency' => 'USD',
                            'amount' => $authorPayoutAmount,
                            'payment_provider' => 'app',
                            'description' => "Immediate author payout for Order ID: {$order->id}",
                            'type' => 'payout',
                            'direction' => 'credit',
                            'purpose_type' => 'order',
                            'purpose_id' => $order->id,
                        ]);
                    }
                }
            }

            // Only dispatch the job on the first successful webhook call.
            // $order->status was 'pending' before this method ran; if it was already
            // 'paid' (Stripe retry), we skip to prevent queuing a duplicate job
            // which would trigger a second Paystack/Stripe bank transfer.
            if (!$wasAlreadyPaid && $order->payout_status === 'pending') {
                $delayDays = 7;
                ProcessAuthorPayout::dispatch(purpose: 'order', purposeId: $order->id)->delay(now()->addDays($delayDays));
            }
        } catch (ModelNotFoundException $e) {
            throw new \Exception('Order record not found: ' . $e->getMessage(), 404, $e);
        } catch (\Throwable $e) {
            throw new \Exception('Failed to process order: ' . $e->getMessage(), 500, $e);
        }
    }

    public function handleAccountUpdated(Account $account): void
    {
        try {
            $user = User::where('kyc_account_id', $account->id)->first();

            if (! $user) {
                return;
            }

            $individual      = $account->individual ?? null;
            $verification    = $individual?->verification ?? null;
            $status          = $verification?->status ?? null;
            $docFront        = $verification?->document?->front ?? null;
            $disabledReason  = $account->requirements?->disabled_reason ?? null;

            if (! $status) {
                return;
            }

            // Stripe has disabled the account — mark as rejected regardless of verification status
            if ($disabledReason && $status !== 'verified') {
                $user->update(['kyc_status' => 'rejected']);
                Log::info('Stripe account disabled', [
                    'user_id' => $user->id,
                    'reason'  => $disabledReason,
                ]);
                return;
            }

            if ($status === 'unverified' && $docFront === null) {
                $user->update(['kyc_status' => 'document-required']);
            } elseif ($status === 'unverified' && $docFront !== null) {
                $user->update(['kyc_status' => 'rejected']);
            } elseif ($status === 'pending' && $docFront !== null) {
                $user->update(['kyc_status' => 'in-review']);
            } elseif ($status === 'pending' && $docFront === null) {
                $user->update(['kyc_status' => 'document-required']);
            } elseif ($status === 'verified') {
                $user->update([
                    'kyc_status' => 'verified',
                    'first_name' => $individual->first_name,
                    'last_name'  => $individual->last_name,
                    'name'       => trim("{$individual->first_name} {$individual->last_name}"),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('handleAccountUpdated failed', [
                'account_id' => $account->id ?? null,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    public function handleExternalAccountCreated($externalAccount): void
    {
        try {
            $user = User::where('kyc_account_id', $externalAccount->account)->first();

            if ($user) {
                // Create PaymentMethod record
                $user->paymentMethods()->create([
                    'provider' => 'stripe',
                    'provider_payment_method_id' => $externalAccount->id,
                    'type' => 'bank', // Change to static 'bank' type
                    'purpose' => 'payout',
                    'country_code' => $externalAccount->country ?? null,
                    'payment_method_data' => [
                        'last4' => $externalAccount->last4 ?? null,
                        'bank_name' => $externalAccount->bank_name ?? null,
                        'country_code' => $externalAccount->country ?? null,
                        'currency' => $externalAccount->currency ?? null,
                        'status' => $externalAccount->status ?? 'active',
                    ],
                    'default' => $externalAccount->default_for_currency ?? false,
                ]);
            } else {
                Log::warning('User not found for external account', [
                    'account_id' => $externalAccount->account
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle external account creation', [
                'error' => $e->getMessage(),
                'external_account_id' => $externalAccount->id ?? null
            ]);
        }
    }

    public function handleExternalAccountRemoved($externalAccount): void
    {
        try {
            $user = User::where('kyc_account_id', $externalAccount->account)->first();

            if ($user) {
                // Remove the payment method
                $user->paymentMethods()
                    ->where('provider', 'stripe')
                    ->where('provider_payment_method_id', $externalAccount->id)
                    ->where('purpose', 'payout')
                    ->delete();

                Log::info('External account removed successfully', [
                    'user_id' => $user->id,
                    'external_account_id' => $externalAccount->id
                ]);
            } else {
                Log::warning('User not found for external account removal', [
                    'account_id' => $externalAccount->account
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle external account removal', [
                'error' => $e->getMessage(),
                'external_account_id' => $externalAccount->id ?? null
            ]);
        }
    }

    /**
     * Handle when a payout is created in Stripe
     */
    public function handlePayoutCreated(Payout $payout): void
    {
        try {
            // Find the user associated with this payout via their Stripe account
            $user = $this->getUserFromStripeAccount($payout->destination);

            if (!$user) {
                Log::warning('Could not find user for payout', ['payout_id' => $payout->id]);
                return;
            }

            // Create or update the payout record using the model's utility method
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if ($stripePayout) {
                $stripePayout->updateFromStripeWebhook($payout->toArray());
            } else {
                StripePayout::createFromStripeObject($user->id, $payout->toArray());
            }

            Log::info('Payout created successfully', ['payout_id' => $payout->id, 'user_id' => $user->id]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout created', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout is updated in Stripe
     */
    public function handlePayoutUpdated(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                // If payout doesn't exist, create it (fallback)
                $this->handlePayoutCreated($payout);
                return;
            }

            // Update the payout record
            $stripePayout->update([
                'status' => $payout->status,
                'failure_code' => $payout->failure_code,
                'failure_message' => $payout->failure_message,
                'stripe_response' => $payout->toArray(),
            ]);

            Log::info('Payout updated successfully', ['payout_id' => $payout->id]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout updated', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout is successfully paid in Stripe
     */
    public function handlePayoutPaid(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                Log::warning('Payout record not found for paid event', ['payout_id' => $payout->id]);
                return;
            }

            // Update the payout record to paid status
            $stripePayout->update([
                'status' => 'paid',
                'stripe_response' => $payout->toArray(),
            ]);

            Log::info('Payout marked as paid', [
                'payout_id' => $payout->id,
                'user_id' => $stripePayout->user_id,
                'amount' => $stripePayout->amount
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout paid', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle when a payout fails in Stripe
     */
    public function handlePayoutFailed(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                Log::warning('Payout record not found for failed event', ['payout_id' => $payout->id]);
                return;
            }

            $stripePayout->update([
                'status'          => 'failed',
                'failure_code'    => $payout->failure_code,
                'failure_message' => $payout->failure_message,
                'stripe_response' => $payout->toArray(),
            ]);

            Log::error('Stripe payout failed', [
                'payout_id'       => $payout->id,
                'user_id'         => $stripePayout->user_id,
                'failure_code'    => $payout->failure_code,
                'failure_message' => $payout->failure_message,
            ]);

            // Notify the author by email so they can fix their bank details
            $author = \App\Models\User::find($stripePayout->user_id);
            if ($author?->email) {
                $humanReason = match ($payout->failure_code) {
                    'account_closed'             => 'The destination bank account has been closed.',
                    'account_frozen'             => 'The destination bank account is frozen.',
                    'bank_account_restricted'    => 'The bank account has restrictions that prevent this payout.',
                    'debit_not_authorized'       => 'Your bank does not allow debits from this account.',
                    'insufficient_funds'         => 'Insufficient funds in the platform account.',
                    'invalid_account_number'     => 'The bank account number on file appears to be invalid.',
                    'no_account'                 => 'No bank account was found with the provided details.',
                    default                      => $payout->failure_message ?? 'An unknown error occurred.',
                };

                \Illuminate\Support\Facades\Mail::to($author->email)->queue(
                    new \App\Mail\Generic\GenericAppNotification(
                        'SBA Reads — Payout Failed',
                        "Hi {$author->first_name},\n\n"
                        . "A withdrawal of {$stripePayout->currency} {$stripePayout->amount} to your bank account could not be completed.\n\n"
                        . "Reason: {$humanReason}\n\n"
                        . "Please log in to the SBA Reads app, go to Wallet → Payout Method, and verify your bank account details are correct.\n\n"
                        . "If you need help, contact us at support@sbareads.com.\n\n"
                        . "— The SBA Reads Team"
                    )
                );
            }
        } catch (\Exception $e) {
            Log::error('Failed to handle payout failed event', [
                'payout_id' => $payout->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle when a payout is canceled in Stripe
     */
    public function handlePayoutCanceled(Payout $payout): void
    {
        try {
            $stripePayout = StripePayout::where('stripe_payout_id', $payout->id)->first();

            if (!$stripePayout) {
                Log::warning('Payout record not found for canceled event', ['payout_id' => $payout->id]);
                return;
            }

            // Update the payout record to canceled status
            $stripePayout->update([
                'status' => 'canceled',
                'stripe_response' => $payout->toArray(),
            ]);

            // Add the canceled amount back to user's wallet
            $stripePayout->user->increment('wallet_balance', $stripePayout->amount);

            Log::info('Payout canceled and amount refunded to wallet', [
                'payout_id' => $payout->id,
                'user_id' => $stripePayout->user_id,
                'amount' => $stripePayout->amount
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to handle payout canceled', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function formatAmount(float $amount, string $currency): string
    {
        $symbol = strtoupper($currency) === 'NGN' ? '₦' : '$';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Helper method to find user by Stripe account/destination
     */
    private function getUserFromStripeAccount(string $destination): ?User
    {
        // Method 1: Find user by their payment methods that match this destination
        $user = User::whereHas('paymentMethods', function ($query) use ($destination) {
            $query->where('provider_payment_method_id', $destination)
                ->where('provider', 'stripe')
                ->where('purpose', 'payout');
        })->first();

        if ($user) {
            return $user;
        }

        // Method 2: Try to find by Stripe Connect account if the destination is linked to an account
        // This would require additional Stripe API calls to get the account from the destination
        try {
            $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));

            // If destination is a bank account, get the account it belongs to
            if (str_starts_with($destination, 'ba_')) {
                $bankAccount = $stripe->accounts->retrieveExternalAccount(
                    'acct_connected_account_id', // You'd need to determine this
                    $destination
                );

                // Find user by the connected account ID
                $user = User::where('kyc_account_id', $bankAccount->account)->first();
            }
        } catch (\Exception $e) {
            Log::warning('Could not retrieve destination details from Stripe', [
                'destination' => $destination,
                'error' => $e->getMessage()
            ]);
        }

        // Method 3: Look for users who have this destination in their kyc_metadata
        $user = User::whereJsonContains('kyc_metadata->bank_account_id', $destination)
            ->orWhereJsonContains('kyc_metadata->external_accounts', $destination)
            ->first();

        return $user;
    }
}
