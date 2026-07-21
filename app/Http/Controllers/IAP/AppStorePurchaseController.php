<?php

namespace App\Http\Controllers\IAP;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Transaction;
use App\Services\Book\BookPurchaseService;
use App\Services\Paystack\CurrencyConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imdhemy\Purchases\Facades\Product;

// Apple's commission rate: 30% standard, 15% for small businesses.
// We use 30% as the conservative default so author earnings are never overstated.
const APPLE_COMMISSION = 0.30;
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

                $receiptInfo = $receiptResponse->getReceipt()->getInApp();
            }

            Log::info('IAP receipt verified', [
                'user_id'     => $user->id,
                'environment' => $environment,
            ]);

            if (empty($receiptInfo)) {
                // Apple sometimes puts non-consumable purchases in latest_receipt_info
                // instead of receipt.in_app — especially when the client sends a cached
                // receipt that predates the purchase being committed on Apple's servers.
                $receiptInfo = $receiptResponse->getLatestReceiptInfo() ?? [];

                if (! empty($receiptInfo)) {
                    Log::info('IAP: receipt.in_app empty, falling back to latest_receipt_info', [
                        'user_id' => $user->id,
                        'count'   => count($receiptInfo),
                    ]);
                }
            }

            if (empty($receiptInfo)) {
                Log::warning('IAP: no in-app purchases found in receipt', ['user_id' => $user->id]);

                return response()->json(['error' => 'No purchase items found in receipt'], 400);
            }

            // ── Phase 1: Grant books (committed immediately — user gets their book no matter what) ──
            [$grantedBooks, $bookIdsToGrant, $purchaseItems] = DB::transaction(
                function () use ($user, $receiptInfo, $environment, $purchaseType, $bookIdFromRequest) {
                    $grantedBooks        = [];
                    $bookIdsToGrant      = [];
                    $purchaseItems       = []; // passed to Phase 2 for author earnings
                    $bookPurchaseService = app(BookPurchaseService::class);

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

                        if (! $book) {
                            Log::warning("IAP: book not found for product_id: {$productId}", [
                                'user_id' => $user->id,
                            ]);
                            continue;
                        }

                        $existingTransaction = Transaction::where('payment_intent_id', $originalTransId)->first();

                        if (! $existingTransaction) {
                            $amount       = $book->actual_price ?? $book->discounted_price ?? 0;
                            $bookCurrency = strtolower($book->currency ?? 'usd');

                            Transaction::create([
                                'id'               => Str::uuid(),
                                'reference'        => uniqid('iap_'),
                                'user_id'          => $user->id,
                                'payment_intent_id'=> $originalTransId,
                                'amount'           => $amount,
                                'currency'         => 'usd',
                                'payment_provider' => 'apple',
                                'description'      => "Apple IAP {$purchaseType} purchase: {$book->title}",
                                'purpose_type'     => 'Online book purchase',
                                'purpose_id'       => $book->id,
                                'status'           => 'success',
                                'type'             => 'purchase',
                                'direction'        => 'debit',
                                'meta_data'        => [
                                    'product_id'    => $productId,
                                    'book_id'       => $book->id,
                                    'book_currency' => $bookCurrency,
                                    'purchase_type' => $purchaseType,
                                    'environment'   => $environment,
                                ],
                            ]);

                            Log::info('IAP: buyer transaction created', [
                                'user_id'     => $user->id,
                                'book_id'     => $book->id,
                                'environment' => $environment,
                            ]);
                        } else {
                            Log::info('IAP: transaction already exists, skipping', [
                                'user_id' => $user->id,
                                'book_id' => $book->id,
                            ]);
                        }

                        $alreadyPurchased = $user->purchasedBooks()->where('books.id', $book->id)->exists();

                        if (! $alreadyPurchased) {
                            $bookIdsToGrant[] = $book->id;
                            $grantedBooks[]   = [
                                'id'         => $book->id,
                                'product_id' => $productId,
                                'title'      => $book->title,
                            ];
                            $purchaseItems[]  = [
                                'book_id'    => $book->id,
                                'book_title' => $book->title,
                                'price'      => (float) ($book->actual_price ?? $book->discounted_price ?? 0),
                                'buyer_id'   => $user->id,
                            ];
                        } else {
                            Log::info('IAP: book already in library', [
                                'user_id' => $user->id,
                                'book_id' => $book->id,
                            ]);
                        }
                    }

                    if (! empty($bookIdsToGrant)) {
                        $bookPurchaseService->addBooksToUserLibrary($user, $bookIdsToGrant);
                    }

                    return [$grantedBooks, $bookIdsToGrant, $purchaseItems];
                }
            );

            Log::info('IAP: books granted', [
                'user_id'      => $user->id,
                'environment'  => $environment,
                'books_granted'=> count($grantedBooks),
            ]);

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

                        foreach ($authors as $author) {
                            $alreadyCredited = Transaction::where('payment_intent_id', 'iap_author_' . $user->id . '_' . $item['book_id'])
                                ->where('user_id', $author->id)
                                ->exists();

                            if ($alreadyCredited) continue;

                            Transaction::create([
                                'id'               => Str::uuid(),
                                'user_id'          => $author->id,
                                'reference'        => uniqid('iap_earn_'),
                                'payment_intent_id'=> 'iap_author_' . $user->id . '_' . $item['book_id'],
                                'amount'           => $authorEarning,
                                'currency'         => 'USD',
                                'payment_provider' => 'apple',
                                'status'           => 'iap_pending',
                                'type'             => 'earning',
                                'direction'        => 'credit',
                                'description'      => "App Store sale: {$item['book_title']} (pending Apple remittance)",
                                'purpose_type'     => 'iap_book_purchase',
                                'purpose_id'       => $item['book_id'],
                                'meta_data'        => [
                                    'buyer_id'         => $item['buyer_id'],
                                    'book_id'          => $item['book_id'],
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
                'message'     => count($grantedBooks) > 0
                    ? 'Purchase verified successfully'
                    : 'All books in receipt are already in your library',
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
