<?php

namespace App\Http\Controllers\IAP;

use App\Http\Controllers\Controller;
use App\Models\AudioBookPurchase;
use App\Models\Book;
use App\Models\Transaction;
use App\Services\Book\BookPurchaseService;
use App\Services\Paystack\CurrencyConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imdhemy\AppStore\Jws\Parser;
use Imdhemy\AppStore\ServerNotifications\V2DecodedPayload;
use Imdhemy\Purchases\Facades\Product;

// Apple's commission rate:
//   30% standard (most developers)
//   15% if enrolled in Apple Small Business Program (annual App Store revenue < $1M)
// Verify your program enrollment in App Store Connect → Agreements, Tax, and Banking.
// Using 0.15 (Small Business Program). Change to 0.30 if not enrolled.
const APPLE_COMMISSION = 0.15;
const AUTHOR_REVENUE_SHARE = 0.75;

class AppStorePurchaseController extends Controller
{
    private const PRODUCTION_URL = 'https://buy.itunes.apple.com/verifyReceipt';
    private const SANDBOX_URL    = 'https://sandbox.itunes.apple.com/verifyReceipt';

    /**
     * Verify an Apple IAP receipt.
     *
     * Apple's recommended flow:
     *  1. Send to production endpoint.
     *  2. If status 21007 comes back, it's a sandbox/TestFlight receipt — retry
     *     against the sandbox endpoint.
     *  3. Record which environment validated the receipt for traceability.
     */
    public function verifyPurchase(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'receipt_data'  => 'required|string',
            'password'      => 'nullable|string',
            'purchase_type' => 'nullable|string|in:book,audio',
            'book_id'       => 'nullable|integer',
        ]);

        $purchaseType      = $request->input('purchase_type', 'book');
        $bookIdFromRequest = (int) $request->input('book_id', 0);

        Log::info('IAP verification started', [
            'user_id'       => $user->id,
            'purchase_type' => $purchaseType,
            'book_id'       => $bookIdFromRequest,
        ]);

        try {
            // --- Step 1: Try production endpoint via imdhemy library ---
            $receiptResponse = Product::appStore()
                ->receiptData($request->receipt_data)
                ->password(config('liap.appstore_password'))
                ->verifyReceipt();

            $statusCode  = $receiptResponse->getStatus()->getValue();
            $environment = 'production';

            Log::info('IAP: Apple verifyReceipt response', [
                'user_id'         => $user->id,
                'status_code'     => $statusCode,
                'has_password'    => ! empty(config('liap.appstore_password')),
                'in_app_count'    => count($receiptResponse->getReceipt()?->getInApp() ?? []),
                'latest_count'    => count($receiptResponse->getLatestReceiptInfo() ?? []),
                'book_id_request' => $bookIdFromRequest,
            ]);

            // --- Step 2: Status 21007 = sandbox receipt sent to production ---
            // Retry directly against Apple's sandbox endpoint.
            if ($statusCode === 21007) {
                Log::info('IAP: sandbox receipt detected (status 21007), retrying against sandbox endpoint', [
                    'user_id' => $user->id,
                ]);

                [$statusCode, $receiptInfo, $environment] = $this->verifySandboxReceipt(
                    $request->receipt_data,
                    config('liap.appstore_password'),
                );

                if ($statusCode !== 0) {
                    Log::warning('IAP sandbox verification failed', [
                        'user_id'     => $user->id,
                        'status_code' => $statusCode,
                        'environment' => $environment,
                    ]);

                    return response()->json([
                        'error'       => 'Invalid receipt (sandbox)',
                        'status_code' => $statusCode,
                        'environment' => $environment,
                    ], 400);
                }
            } else {
                // Production response — extract in-app items from the imdhemy object
                if (! $receiptResponse->getStatus()->isValid()) {
                    Log::warning('IAP production receipt invalid', [
                        'user_id'     => $user->id,
                        'status_code' => $statusCode,
                    ]);

                    return response()->json([
                        'error'       => 'Invalid receipt',
                        'status_code' => $statusCode,
                        'environment' => $environment,
                    ], 400);
                }

                // Merge receipt.in_app + latest_receipt_info — Apple may place a fresh
                // purchase in either array. receipt.in_app can be non-empty with old
                // purchases while the newest transaction only appears in latest_receipt_info.
                // Deduplicating by original_transaction_id prevents double-grants.
                $inAppItems  = $receiptResponse->getReceipt()?->getInApp() ?? [];
                $latestItems = $receiptResponse->getLatestReceiptInfo() ?? [];

                $seen        = [];
                $receiptInfo = [];
                foreach (array_merge($inAppItems, $latestItems) as $item) {
                    $txId = is_array($item)
                        ? ($item['original_transaction_id'] ?? '')
                        : $item->getOriginalTransactionId();
                    if ($txId && ! isset($seen[$txId])) {
                        $seen[$txId] = true;
                        $receiptInfo[] = $item;
                    }
                }
            }

            Log::info('IAP receipt verified', [
                'user_id'      => $user->id,
                'environment'  => $environment,
                'merged_count' => count($receiptInfo),
            ]);

            if (empty($receiptInfo)) {
                Log::warning('IAP: no in-app purchases found in receipt', ['user_id' => $user->id]);

                return response()->json(['error' => 'No purchase items found in receipt'], 400);
            }

            // ── Phase 1: Grant books (committed immediately — user gets their book no matter what) ──
            [$grantedBooks, $bookIdsToGrant, $audioBookIdsGranted, $purchaseItems, $confirmedBookIds] = DB::transaction(
                function () use ($user, $receiptInfo, $environment, $purchaseType, $bookIdFromRequest) {
                    $grantedBooks        = [];
                    $bookIdsToGrant      = [];
                    $audioBookIdsGranted = []; // book IDs for which AudioBookPurchase was created
                    $purchaseItems       = []; // passed to Phase 2 for author earnings
                    $bookPurchaseService = app(BookPurchaseService::class);

                    // Tracks every book that is confirmed in the user's library after this
                    // call — whether just granted or already owned. Returned to the caller
                    // so the frontend knows the receipt was definitively processed and can
                    // safely delete its local retry key.
                    $confirmedBookIds = [];

                    foreach ($receiptInfo as $item) {
                        // $item is either an imdhemy InAppPurchase object (production path)
                        // or a plain array (sandbox path via raw HTTP).
                        $productId       = is_array($item) ? $item['product_id']              : $item->getProductId();
                        $originalTransId = is_array($item) ? $item['original_transaction_id'] : $item->getOriginalTransactionId();

                        // Lookup by the book's own product_id (per-book SKU system).
                        $book = Book::where('product_id', $productId)
                            ->orWhere('audio_product_id', $productId)
                            ->first();

                        // Fallback: product_id not yet in DB but book_id was sent by client.
                        if (! $book && $bookIdFromRequest) {
                            $book = Book::find($bookIdFromRequest);
                        }

                        // Fallback: "Restore Purchases" and auto-reconcile never send book_id.
                        // If the original purchase already created a transaction, its meta_data
                        // has the book_id — use it so restore works even without product_id set.
                        $knownTx = null;
                        if (! $book) {
                            $knownTx = Transaction::where('payment_intent_id', $originalTransId)
                                ->whereIn('status', ['succeeded', 'success'])
                                ->first();
                            if ($knownTx && is_array($knownTx->meta_data) && ! empty($knownTx->meta_data['book_id'])) {
                                $book = Book::find((int) $knownTx->meta_data['book_id']);
                            }
                        }

                        if (! $book) {
                            Log::warning("IAP: book not found for product_id: {$productId}", [
                                'user_id' => $user->id,
                            ]);
                            continue;
                        }

                        // Determine whether this SKU is an audio product or a book product.
                        $isAudioProduct = $book->audio_product_id && $book->audio_product_id === $productId;
                        $effectiveType  = $isAudioProduct ? 'audio' : $purchaseType;

                        // Reuse $knownTx when the fallback already fetched it to avoid a second query.
                        $existingTransaction = $knownTx ?? Transaction::where('payment_intent_id', $originalTransId)->first();

                        if (! $existingTransaction) {
                            $amount = $isAudioProduct
                                ? (float) ($book->audio_price ?? 10.00)
                                : (float) ($book->actual_price ?? $book->discounted_price ?? 0);
                            $bookCurrency = strtolower($book->currency ?? 'usd');

                            Transaction::create([
                                'id'               => Str::uuid(),
                                'reference'        => uniqid('iap_'),
                                'user_id'          => $user->id,
                                'payment_intent_id'=> $originalTransId,
                                'amount'           => $amount,
                                'currency'         => 'usd',
                                'payment_provider' => 'apple',
                                'description'      => "Apple IAP {$effectiveType} purchase: {$book->title}",
                                'purpose_type'     => 'Online book purchase',
                                'purpose_id'       => $book->id,
                                'status'           => 'succeeded',
                                'type'             => 'purchase',
                                'direction'        => 'debit',
                                'meta_data'        => [
                                    'product_id'    => $productId,
                                    'book_id'       => $book->id,
                                    'book_currency' => $bookCurrency,
                                    'purchase_type' => $effectiveType,
                                    'environment'   => $environment,
                                ],
                            ]);

                            Log::info('IAP: buyer transaction created', [
                                'user_id'        => $user->id,
                                'book_id'        => $book->id,
                                'purchase_type'  => $effectiveType,
                                'environment'    => $environment,
                            ]);
                        } else {
                            Log::info('IAP: transaction already exists, skipping', [
                                'user_id' => $user->id,
                                'book_id' => $book->id,
                            ]);
                        }

                        if ($isAudioProduct) {
                            // ── Audio product: grant via AudioBookPurchase table ──
                            $alreadyHasAudio = \App\Models\AudioBookPurchase::where('user_id', $user->id)
                                ->where('book_id', $book->id)
                                ->where('status', 'paid')
                                ->exists();

                            if (! $alreadyHasAudio) {
                                $audioPrice   = (float) ($book->audio_price ?? 10.00);
                                $netFromApple = $audioPrice * (1 - APPLE_COMMISSION);
                                $authorPayout = round($netFromApple * AUTHOR_REVENUE_SHARE, 2);
                                $platformFee  = round($audioPrice - $authorPayout, 2);

                                \App\Models\AudioBookPurchase::create([
                                    'user_id'              => $user->id,
                                    'book_id'              => $book->id,
                                    'author_id'            => $book->author_id,
                                    'price'                => $audioPrice,
                                    'author_payout_amount' => $authorPayout,
                                    'platform_fee_amount'  => $platformFee,
                                    'currency'             => 'usd',
                                    'status'               => 'paid',
                                    'payout_status'        => 'pending',
                                    'payment_provider'     => 'apple',
                                    'payment_intent_id'    => $originalTransId,
                                ]);

                                $grantedBooks[]  = [
                                    'id'           => $book->id,
                                    'product_id'   => $productId,
                                    'title'        => $book->title,
                                    'is_audio'     => true,
                                ];
                                $purchaseItems[] = [
                                    'book_id'    => $book->id,
                                    'book_title' => $book->title,
                                    'price'      => $audioPrice,
                                    'buyer_id'   => $user->id,
                                    'is_audio'   => true,
                                ];

                                $audioBookIdsGranted[] = $book->id;

                                Log::info('IAP: audio access granted', [
                                    'user_id' => $user->id,
                                    'book_id' => $book->id,
                                ]);
                            }

                            // Also grant full book read access (buying audio entitles
                            // the reader to the text too — consistent with handleNotification).
                            if (! $bookPurchaseService->userOwnsBook($user, $book->id)) {
                                $bookIdsToGrant[] = $book->id;
                            }

                            $confirmedBookIds[] = $book->id;
                        } else {
                            // ── Book product: grant via book_user table ──
                            $alreadyPurchased = $bookPurchaseService->userOwnsBook($user, $book->id);

                            if (! $alreadyPurchased) {
                                $bookIdsToGrant[]   = $book->id;
                                $confirmedBookIds[]  = $book->id;
                                $grantedBooks[]      = [
                                    'id'         => $book->id,
                                    'product_id' => $productId,
                                    'title'      => $book->title,
                                ];
                                $purchaseItems[]     = [
                                    'book_id'    => $book->id,
                                    'book_title' => $book->title,
                                    'price'      => (float) ($book->actual_price ?? $book->discounted_price ?? 0),
                                    'buyer_id'   => $user->id,
                                ];
                            } else {
                                // Book already in library — still confirmed so the client clears its retry key.
                                $confirmedBookIds[] = $book->id;
                                Log::info('IAP: book already in library', [
                                    'user_id' => $user->id,
                                    'book_id' => $book->id,
                                ]);
                            }
                        }
                    }

                    // ── Grant books to primary user and all linked accounts ──
                    if (! empty($bookIdsToGrant)) {
                        $bookPurchaseService->addBooksToUserLibrary($user, $bookIdsToGrant);

                        // Grant to every other account sharing the same email (reader/author dual-account).
                        $linkedUsers = \App\Models\User::where('email', $user->email)
                            ->where('id', '!=', $user->id)
                            ->get();
                        foreach ($linkedUsers as $linked) {
                            $unowned = array_values(array_filter(
                                $bookIdsToGrant,
                                fn ($id) => ! $bookPurchaseService->userOwnsBook($linked, $id)
                            ));
                            if (! empty($unowned)) {
                                $bookPurchaseService->addBooksToUserLibrary($linked, $unowned);
                                Log::info('IAP: book granted to linked account', [
                                    'primary_user_id' => $user->id,
                                    'linked_user_id'  => $linked->id,
                                    'book_ids'        => $unowned,
                                ]);
                            }
                        }
                    }

                    // ── Grant audio access to all linked accounts ──
                    if (! empty($audioBookIdsGranted)) {
                        $linkedUsers = $linkedUsers ?? \App\Models\User::where('email', $user->email)
                            ->where('id', '!=', $user->id)
                            ->get();
                        foreach ($linkedUsers as $linked) {
                            foreach ($audioBookIdsGranted as $audioBookId) {
                                $linkedHasAudio = \App\Models\AudioBookPurchase::where('user_id', $linked->id)
                                    ->where('book_id', $audioBookId)
                                    ->where('status', 'paid')
                                    ->exists();
                                if (! $linkedHasAudio) {
                                    $linkedBook   = Book::find($audioBookId);
                                    $audioPrice   = (float) ($linkedBook?->audio_price ?? 10.00);
                                    $netFromApple = $audioPrice * (1 - APPLE_COMMISSION);
                                    $authorPayout = round($netFromApple * AUTHOR_REVENUE_SHARE, 2);
                                    $platformFee  = round($audioPrice - $authorPayout, 2);

                                    \App\Models\AudioBookPurchase::create([
                                        'user_id'              => $linked->id,
                                        'book_id'              => $audioBookId,
                                        'author_id'            => $linkedBook?->author_id,
                                        'price'                => $audioPrice,
                                        'author_payout_amount' => $authorPayout,
                                        'platform_fee_amount'  => $platformFee,
                                        'currency'             => 'usd',
                                        'status'               => 'paid',
                                        'payout_status'        => 'pending',
                                        'payment_provider'     => 'apple',
                                        'payment_intent_id'    => 'iap_linked_audio_' . $user->id . '_' . $audioBookId,
                                    ]);
                                    Log::info('IAP: audio access granted to linked account', [
                                        'primary_user_id' => $user->id,
                                        'linked_user_id'  => $linked->id,
                                        'book_id'         => $audioBookId,
                                    ]);
                                }
                            }
                        }
                    }

                    return [$grantedBooks, $bookIdsToGrant, $audioBookIdsGranted, $purchaseItems, $confirmedBookIds];
                }
            );

            Log::info('IAP: books granted', [
                'user_id'      => $user->id,
                'environment'  => $environment,
                'books_granted'=> count($grantedBooks),
            ]);

            // Detect Apple receipt propagation delay: the client sent a specific book_id
            // but that book's transaction is not yet present in the receipt. This happens
            // when the unified receipt hasn't been updated yet on Apple's servers immediately
            // after a purchase. Signal the client to retry with forceRefresh:true after a
            // short backoff rather than treating this as a permanent failure.
            $pending = false;
            if ($bookIdFromRequest > 0 && ! in_array($bookIdFromRequest, $confirmedBookIds)) {
                $pending = true;
                Log::info('IAP: book_id provided but not found in receipt — possible Apple propagation delay', [
                    'user_id' => $user->id,
                    'book_id' => $bookIdFromRequest,
                ]);
            }

            // ── Phase 2: Record author earnings (non-critical — runs after book grant is committed) ──
            // A failure here never revokes book access. Logged for manual reconciliation.
            if (! empty($purchaseItems)) {
                try {
                    $rateAtSale = null;
                    try {
                        $rateAtSale = app(CurrencyConversionService::class)->getExchangeRate('USD', 'NGN');
                    } catch (\Throwable) {
                        $rateAtSale = (float) config('services.currency.ngn_usd_fallback', 1600);
                    }

                    foreach ($purchaseItems as $item) {
                        $book    = Book::find($item['book_id']);
                        $authors = $book?->authors ?? collect();
                        $price   = $item['price'];

                        $netFromApple  = $price * (1 - APPLE_COMMISSION);
                        $authorEarning = round($netFromApple * AUTHOR_REVENUE_SHARE, 2);

                        $isAudioItem  = ! empty($item['is_audio']);
                        $earningKey   = 'iap_author_' . $user->id . '_' . $item['book_id'] . ($isAudioItem ? '_audio' : '');
                        $purposeType  = $isAudioItem ? 'iap_audio_purchase' : 'iap_book_purchase';
                        $description  = $isAudioItem
                            ? "App Store audio sale: {$item['book_title']} (pending Apple remittance)"
                            : "App Store sale: {$item['book_title']} (pending Apple remittance)";

                        foreach ($authors as $author) {
                            $alreadyCredited = Transaction::where('payment_intent_id', $earningKey)
                                ->where('user_id', $author->id)
                                ->exists();

                            if ($alreadyCredited) continue;

                            Transaction::create([
                                'id'               => Str::uuid(),
                                'user_id'          => $author->id,
                                'reference'        => uniqid('iap_earn_'),
                                'payment_intent_id'=> $earningKey,
                                'amount'           => $authorEarning,
                                'currency'         => 'USD',
                                'payment_provider' => 'apple',
                                'status'           => 'iap_pending',
                                'type'             => 'earning',
                                'direction'        => 'credit',
                                'description'      => $description,
                                'purpose_type'     => $purposeType,
                                'purpose_id'       => $item['book_id'],
                                'meta_data'        => [
                                    'buyer_id'         => $item['buyer_id'],
                                    'book_id'          => $item['book_id'],
                                    'is_audio'         => $isAudioItem,
                                    'gross_price'      => $price,
                                    'apple_cut'        => round($price * APPLE_COMMISSION, 2),
                                    'net_from_apple'   => round($netFromApple, 2),
                                    'author_earning'   => $authorEarning,
                                    'environment'      => $environment,
                                    'ngn_rate_at_sale' => $rateAtSale,
                                ],
                            ]);

                            Log::info('IAP: author pending earning created', [
                                'author_id'       => $author->id,
                                'book_id'         => $item['book_id'],
                                'amount_usd'      => $authorEarning,
                                'ngn_rate_locked' => $rateAtSale,
                                'environment'     => $environment,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('IAP: author earnings recording failed — book already granted, reconcile manually', [
                        'user_id'  => $user->id,
                        'book_ids' => $bookIdsToGrant,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'status'      => 'success',
                'environment' => $environment,
                'books'       => $grantedBooks,
                // confirmed = true means the purchase is definitively accounted for
                // (either just granted or already in the user's library). The client
                // uses this to decide whether it is safe to delete its local retry key.
                'confirmed'   => ! empty($confirmedBookIds),
                // pending = true means book_id was provided but its transaction is not
                // yet in the receipt — Apple propagation delay. Client should retry.
                'pending'     => $pending,
                'message'     => count($grantedBooks) > 0
                    ? 'Purchase verified successfully'
                    : ($pending ? 'Purchase pending Apple receipt update' : 'All books in receipt are already in your library'),
            ]);

        } catch (\Exception $e) {
            Log::error('IAP verification exception: '.$e->getMessage(), [
                'user_id' => $user->id ?? null,
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Verification failed'], 500);
        }
    }

    /**
     * Create a purchase intent — stores userId + bookId against a UUID that the
     * client passes as `appAccountToken` when calling Apple's StoreKit.
     * Apple includes this UUID in every server notification for that purchase,
     * allowing us to identify the buyer without relying on the client to send
     * the receipt back to us.
     */
    public function createPurchaseIntent(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'book_id'       => 'required|integer|exists:books,id',
            'purchase_type' => 'nullable|string|in:book,audio',
        ]);

        $bookId       = (int) $request->input('book_id');
        $purchaseType = $request->input('purchase_type', 'book');
        $uuid         = (string) Str::uuid();

        // Store for 7 days — plenty of time for Apple to send the notification.
        Cache::put("iap_intent:{$uuid}", [
            'user_id'       => $user->id,
            'book_id'       => $bookId,
            'purchase_type' => $purchaseType,
        ], now()->addDays(7));

        Log::info('IAP: purchase intent created', [
            'user_id'  => $user->id,
            'book_id'  => $bookId,
            'uuid'     => $uuid,
        ]);

        return response()->json(['app_account_token' => $uuid]);
    }

    /**
     * Handle Apple App Store Server Notifications (V2).
     *
     * Apple calls this endpoint directly for every purchase, refund, and
     * renewal — completely independent of the client app. This is the safety
     * net that ensures books are granted and transactions are recorded even
     * when the client's receipt validation fails (e.g. Apple ID session
     * expired, poor network, app backgrounded).
     *
     * Configure the notification URL in App Store Connect:
     *   App Store Connect → Your App → App Information → App Store Server Notifications
     *   Production URL: https://api.sbareads.com/api/iap/appstore/notifications
     *   Sandbox URL: same (we detect environment from the payload)
     *
     * No auth middleware — Apple calls this without our user tokens.
     */
    public function handleNotification(Request $request): JsonResponse
    {
        $signedPayload = $request->input('signedPayload');

        if (! $signedPayload) {
            Log::warning('IAP notification: missing signedPayload');
            return response()->json(['ok' => false], 400);
        }

        try {
            $outerJws  = Parser::toJws($signedPayload);
            $payload   = V2DecodedPayload::fromJws($outerJws);
            $type      = $payload->getType();

            Log::info('IAP notification received', [
                'type'    => $type,
                'subtype' => $payload->getSubType(),
                'uuid'    => $payload->getNotificationUUID(),
            ]);

            // We only act on new one-time purchases. Ignore renewals, refunds, etc.
            if ($type !== V2DecodedPayload::TYPE_ONE_TIME_CHARGE) {
                return response()->json(['ok' => true, 'skipped' => true]);
            }

            $txInfo   = $payload->getTransactionInfo();
            $productId = $txInfo->getProductId();
            $origTxId  = $txInfo->getOriginalTransactionId();
            $appToken  = $txInfo->getAppAccountToken();
            $environment = $txInfo->getEnvironment() ?? 'Production';

            Log::info('IAP notification: ONE_TIME_CHARGE', [
                'product_id'        => $productId,
                'original_tx_id'    => $origTxId,
                'app_account_token' => $appToken,
                'environment'       => $environment,
            ]);

            // Look up the user and book from the purchase intent.
            $intent = $appToken ? Cache::get("iap_intent:{$appToken}") : null;

            if (! $intent) {
                // No intent found — either old purchase (pre-intent system) or
                // corrupted token. Try matching by originalTransactionId.
                $existingTx = Transaction::where('payment_intent_id', $origTxId)->first();
                if ($existingTx) {
                    Log::info('IAP notification: transaction already recorded', [
                        'original_tx_id' => $origTxId,
                    ]);
                    return response()->json(['ok' => true, 'already_recorded' => true]);
                }

                Log::warning('IAP notification: no intent and no existing transaction — cannot identify buyer', [
                    'product_id'     => $productId,
                    'original_tx_id' => $origTxId,
                    'app_account_token' => $appToken,
                ]);
                return response()->json(['ok' => true, 'unmatched' => true]);
            }

            $userId       = $intent['user_id'];
            $bookId       = $intent['book_id'];
            $purchaseType = $intent['purchase_type'] ?? 'book';

            $user = \App\Models\User::find($userId);
            $book = Book::find($bookId);

            if (! $user || ! $book) {
                Log::error('IAP notification: user or book not found from intent', compact('userId', 'bookId'));
                // Return 200 — a missing user/book is a permanent failure; returning
                // non-200 would cause Apple to retry indefinitely.
                return response()->json(['ok' => true, 'error' => 'user_or_book_not_found']);
            }

            $bookPurchaseService = app(BookPurchaseService::class);

            DB::transaction(function () use ($user, $book, $origTxId, $productId, $purchaseType, $environment, $bookPurchaseService) {
                $existing = Transaction::where('payment_intent_id', $origTxId)->first();

                if (! $existing) {
                    $amount = $purchaseType === 'audio'
                        ? ($book->audio_price ?? 10.00)
                        : ($book->actual_price ?? $book->discounted_price ?? 0);

                    Transaction::create([
                        'id'                => Str::uuid(),
                        'reference'         => uniqid('iap_notif_'),
                        'user_id'           => $user->id,
                        'payment_intent_id' => $origTxId,
                        'amount'            => $amount,
                        'currency'          => 'usd',
                        'payment_provider'  => 'apple',
                        'description'       => "Apple IAP {$purchaseType} purchase (server notification): {$book->title}",
                        'purpose_type'      => 'Online book purchase',
                        'purpose_id'        => $book->id,
                        'status'            => 'succeeded',
                        'type'              => 'purchase',
                        'direction'         => 'debit',
                        'meta_data'         => [
                            'product_id'    => $productId,
                            'book_id'       => $book->id,
                            'purchase_type' => $purchaseType,
                            'environment'   => $environment,
                            'source'        => 'server_notification',
                        ],
                    ]);

                    Log::info('IAP notification: buyer transaction created', [
                        'user_id'       => $user->id,
                        'book_id'       => $book->id,
                        'purchase_type' => $purchaseType,
                    ]);
                }

                if ($purchaseType === 'audio') {
                    // Audio grants go to AudioBookPurchase, not book_user.
                    $alreadyHasAudio = AudioBookPurchase::where('user_id', $user->id)
                        ->where('book_id', $book->id)
                        ->where('status', 'paid')
                        ->exists();

                    if (! $alreadyHasAudio) {
                        $audioPrice   = (float) ($book->audio_price ?? 10.00);
                        $netFromApple = $audioPrice * (1 - APPLE_COMMISSION);
                        $authorPayout = round($netFromApple * AUTHOR_REVENUE_SHARE, 2);
                        $platformFee  = round($audioPrice - $authorPayout, 2);

                        AudioBookPurchase::create([
                            'user_id'              => $user->id,
                            'book_id'              => $book->id,
                            'author_id'            => $book->author_id,
                            'price'                => $audioPrice,
                            'author_payout_amount' => $authorPayout,
                            'platform_fee_amount'  => $platformFee,
                            'currency'             => 'usd',
                            'status'               => 'paid',
                            'payout_status'        => 'pending',
                            'payment_provider'     => 'apple',
                            'payment_intent_id'    => $origTxId,
                        ]);
                        Log::info('IAP notification: audio access granted', [
                            'user_id' => $user->id,
                            'book_id' => $book->id,
                        ]);
                    }
                } else {
                    if (! $bookPurchaseService->userOwnsBook($user, $book->id)) {
                        $bookPurchaseService->addBooksToUserLibrary($user, [$book->id]);
                        Log::info('IAP notification: book granted', [
                            'user_id' => $user->id,
                            'book_id' => $book->id,
                        ]);
                    }

                    // Also grant to every linked account sharing the same email.
                    $linkedUsers = \App\Models\User::where('email', $user->email)
                        ->where('id', '!=', $user->id)
                        ->get();
                    foreach ($linkedUsers as $linked) {
                        if (! $bookPurchaseService->userOwnsBook($linked, $book->id)) {
                            $bookPurchaseService->addBooksToUserLibrary($linked, [$book->id]);
                            Log::info('IAP notification: book granted to linked account', [
                                'primary_user_id' => $user->id,
                                'linked_user_id'  => $linked->id,
                                'book_id'         => $book->id,
                            ]);
                        }
                    }
                }
            });

            // Record author earning (non-critical, outside transaction).
            $this->recordAuthorEarning($user, $book, $origTxId, $environment, $purchaseType);

            // Clear the intent so it can't be replayed.
            if ($appToken) {
                Cache::forget("iap_intent:{$appToken}");
            }

            return response()->json(['ok' => true, 'granted' => true]);

        } catch (\Throwable $e) {
            Log::error('IAP notification: processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 so Apple doesn't retry endlessly for a logic error.
            return response()->json(['ok' => false, 'error' => 'processing_failed']);
        }
    }

    private function recordAuthorEarning(\App\Models\User $buyer, Book $book, string $origTxId, string $environment, string $purchaseType = 'book'): void
    {
        try {
            $rateAtSale = null;
            try {
                $rateAtSale = app(CurrencyConversionService::class)->getExchangeRate('USD', 'NGN');
            } catch (\Throwable) {
                $rateAtSale = (float) config('services.currency.ngn_usd_fallback', 1600);
            }

            $price = $purchaseType === 'audio'
                ? (float) ($book->audio_price ?? 10.00)
                : (float) ($book->actual_price ?? $book->discounted_price ?? 0);
            $netFromApple  = $price * (1 - APPLE_COMMISSION);
            $authorEarning = round($netFromApple * AUTHOR_REVENUE_SHARE, 2);

            foreach ($book->authors as $author) {
                $alreadyCredited = Transaction::where('payment_intent_id', 'iap_author_' . $buyer->id . '_' . $book->id)
                    ->where('user_id', $author->id)
                    ->exists();

                if ($alreadyCredited) continue;

                Transaction::create([
                    'id'                => Str::uuid(),
                    'user_id'           => $author->id,
                    'reference'         => uniqid('iap_earn_notif_'),
                    'payment_intent_id' => 'iap_author_' . $buyer->id . '_' . $book->id,
                    'amount'            => $authorEarning,
                    'currency'          => 'USD',
                    'payment_provider'  => 'apple',
                    'status'            => 'iap_pending',
                    'type'              => 'earning',
                    'direction'         => 'credit',
                    'description'       => "App Store sale (server notification): {$book->title} (pending Apple remittance)",
                    'purpose_type'      => 'iap_book_purchase',
                    'purpose_id'        => $book->id,
                    'meta_data'         => [
                        'buyer_id'         => $buyer->id,
                        'book_id'          => $book->id,
                        'gross_price'      => $price,
                        'apple_cut'        => round($price * APPLE_COMMISSION, 2),
                        'net_from_apple'   => round($netFromApple, 2),
                        'author_earning'   => $authorEarning,
                        'environment'      => $environment,
                        'ngn_rate_at_sale' => $rateAtSale,
                        'source'           => 'server_notification',
                    ],
                ]);

                Log::info('IAP notification: author earning recorded', [
                    'author_id' => $author->id,
                    'book_id'   => $book->id,
                    'amount'    => $authorEarning,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('IAP notification: author earning failed', [
                'error'   => $e->getMessage(),
                'book_id' => $book->id,
            ]);
        }
    }

    /**
     * Call Apple's sandbox verifyReceipt endpoint directly.
     *
     * Returns [$statusCode, $inAppItems, $environment] where $inAppItems is a
     * plain array so the caller doesn't need the imdhemy object model.
     */
    private function verifySandboxReceipt(string $receiptData, string $password): array
    {
        $response = Http::timeout(15)->post(self::SANDBOX_URL, [
            'receipt-data'              => $receiptData,
            'password'                  => $password,
            'exclude-old-transactions'  => false,
        ]);

        $body       = $response->json();
        $statusCode = (int) ($body['status'] ?? -1);
        $inApp      = $body['receipt']['in_app'] ?? [];

        return [$statusCode, $inApp, 'sandbox'];
    }
}
