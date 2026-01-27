<?php

namespace App\Http\Controllers\IAP;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Transaction;
use App\Services\Book\BookPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imdhemy\Purchases\Facades\Product;

class AppStorePurchaseController extends Controller
{
    public function verifyPurchase(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'receipt_data' => 'required|string',
            'password' => 'nullable|string',
        ]);

        try {
            $receiptResponse = Product::appStore()
                ->receiptData($request->receipt_data)
                ->password(config('liap.appstore_password'))
                ->verifyReceipt();

            if (! $receiptResponse->getStatus()->isValid()) {
                return response()->json(['error' => 'Invalid receipt'], 400);
            }

            $receiptInfo = $receiptResponse->getReceipt()->getInApp();

            if (empty($receiptInfo)) {
                return response()->json(['error' => 'No purchase items found in receipt'], 400);
            }

            return DB::transaction(function () use ($user, $receiptInfo) {
                $grantedBooks = [];
                $bookPurchaseService = app(BookPurchaseService::class);
                $bookIdsToGrant = [];

                foreach ($receiptInfo as $item) {
                    $productId = $item->getProductId();
                    $originalTransId = $item->getOriginalTransactionId();

                    $book = Book::where('product_id', $productId)->first();

                    if (! $book) {
                        Log::warning("Book not found for product_id: {$productId}", [
                            'user_id' => $user->id,
                            'original_transaction_id' => $originalTransId,
                        ]);

                        continue;
                    }

                    $existingTransaction = Transaction::where('payment_intent_id', $originalTransId)->first();

                    if (! $existingTransaction) {
                        $amount = $book->actual_price ?? $book->discounted_price ?? 0;
                        $currency = strtolower($book->currency ?? 'usd');

                        Transaction::create([
                            'id' => Str::uuid(),
                            'reference' => uniqid('iap_'),
                            'user_id' => $user->id,
                            'payment_intent_id' => $originalTransId,
                            'amount' => $amount,
                            'currency' => $currency,
                            'payment_provider' => 'apple',
                            'description' => "Apple IAP book purchase: {$book->title}",
                            'purpose_type' => 'Online book purchase',
                            'status' => 'success',
                            'type' => 'purchase',
                            'direction' => 'debit',
                            'meta_data' => [
                                'product_id' => $productId,
                                'book_id' => $book->id,
                            ],
                        ]);
                    }

                    $alreadyPurchased = $user->purchasedBooks()->where('books.id', $book->id)->exists();

                    if (! $alreadyPurchased) {
                        $bookIdsToGrant[] = $book->id;
                        $grantedBooks[] = [
                            'id' => $book->id,
                            'product_id' => $productId,
                            'title' => $book->title,
                        ];
                    }
                }

                if (! empty($bookIdsToGrant)) {
                    $bookPurchaseService->addBooksToUserLibrary($user, $bookIdsToGrant);
                }

                return response()->json([
                    'status' => 'success',
                    'books' => $grantedBooks,
                    'message' => count($grantedBooks) > 0
                        ? 'Purchase verified successfully'
                        : 'All books in receipt are already in your library',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Purchase Error: '.$e->getMessage(), [
                'user_id' => $user->id ?? null,
                'receipt_data_length' => strlen($request->receipt_data ?? ''),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Verification failed'], 500);
        }
    }
}
