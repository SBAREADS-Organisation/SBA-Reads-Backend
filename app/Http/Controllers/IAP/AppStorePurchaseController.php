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

        Log::info('Purchase verification started', ['user_id' => $user->id]);

        try {
            $receiptResponse = Product::appStore()
                ->receiptData($request->receipt_data)
                ->password(config('liap.appstore_password'))
                ->verifyReceipt();

            if (! $receiptResponse->getStatus()->isValid()) {
                Log::warning('Receipt validation failed', [
                    'user_id' => $user->id,
                    'status' => $receiptResponse->getStatus(),
                ]);

                return response()->json(['error' => 'Invalid receipt'], 400);
            }

            Log::info('Receipt verified successfully', ['user_id' => $user->id]);

            $receiptInfo = $receiptResponse->getReceipt()->getInApp();

            if (empty($receiptInfo)) {
                Log::warning('No in-app purchases found in receipt', ['user_id' => $user->id]);

                return response()->json(['error' => 'No purchase items found in receipt'], 400);
            }

            Log::info('Processing in-app purchases', [
                'user_id' => $user->id,
                'item_count' => count($receiptInfo),
            ]);

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

                    if ($existingTransaction) {
                        Log::info('Transaction already exists, skipping creation', [
                            'user_id' => $user->id,
                            'book_id' => $book->id,
                            'original_transaction_id' => $originalTransId,
                        ]);
                    }

                    if (! $existingTransaction) {
                        $amount = $book->actual_price ?? $book->discounted_price ?? 0;
                        $currency = strtolower($book->currency ?? 'usd');

                        Log::info('Creating transaction for in-app purchase', [
                            'user_id' => $user->id,
                            'book_id' => $book->id,
                            'product_id' => $productId,
                            'original_transaction_id' => $originalTransId,
                        ]);

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

                    if ($alreadyPurchased) {
                        Log::info('Book already in user library, skipping grant', [
                            'user_id' => $user->id,
                            'book_id' => $book->id,
                            'product_id' => $productId,
                        ]);
                    }

                    if (! $alreadyPurchased) {
                        Log::info('Granting book to user library', [
                            'user_id' => $user->id,
                            'book_id' => $book->id,
                            'product_id' => $productId,
                        ]);
                        $bookIdsToGrant[] = $book->id;
                        $grantedBooks[] = [
                            'id' => $book->id,
                            'product_id' => $productId,
                            'title' => $book->title,
                        ];
                    }
                }

                if (! empty($bookIdsToGrant)) {
                    Log::info('Adding books to user library', [
                        'user_id' => $user->id,
                        'book_ids' => $bookIdsToGrant,
                        'count' => count($bookIdsToGrant),
                    ]);
                    $bookPurchaseService->addBooksToUserLibrary($user, $bookIdsToGrant);
                }

                Log::info('Purchase verification completed', [
                    'user_id' => $user->id,
                    'books_granted_count' => count($grantedBooks),
                ]);

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
