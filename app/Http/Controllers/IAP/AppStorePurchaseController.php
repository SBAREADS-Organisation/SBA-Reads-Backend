<?php

namespace App\Http\Controllers\IAP;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Transaction;
use App\Services\Book\BookPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imdhemy\Purchases\Facades\Product;

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

        $purchaseType = $request->input('purchase_type', 'book');

        Log::info('IAP verification started', [
            'user_id'       => $user->id,
            'purchase_type' => $purchaseType,
            'book_id'       => $request->book_id,
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
                Log::warning('IAP: no in-app purchases found in receipt', ['user_id' => $user->id]);

                return response()->json(['error' => 'No purchase items found in receipt'], 400);
            }

            return DB::transaction(function () use ($user, $receiptInfo, $environment, $purchaseType) {
                $grantedBooks        = [];
                $bookPurchaseService = app(BookPurchaseService::class);
                $bookIdsToGrant      = [];

                foreach ($receiptInfo as $item) {
                    // $item is either an imdhemy InAppPurchase object (production path)
                    // or a plain array (sandbox path via raw HTTP).
                    $productId       = is_array($item) ? $item['product_id']              : $item->getProductId();
                    $originalTransId = is_array($item) ? $item['original_transaction_id'] : $item->getOriginalTransactionId();

                    $book = Book::where('product_id', $productId)
                        ->orWhere('audio_product_id', $productId)
                        ->first();

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

                        Log::info('IAP: transaction created', [
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

                Log::info('IAP: verification complete', [
                    'user_id'      => $user->id,
                    'environment'  => $environment,
                    'books_granted'=> count($grantedBooks),
                ]);

                return response()->json([
                    'status'      => 'success',
                    'environment' => $environment,
                    'books'       => $grantedBooks,
                    'message'     => count($grantedBooks) > 0
                        ? 'Purchase verified successfully'
                        : 'All books in receipt are already in your library',
                ]);
            });

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
