<?php

namespace App\Http\Controllers\IAP;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\Transaction;
use App\Services\Book\BookPurchaseService;
use App\Services\IAP\IAPTierService;
use App\Services\Paystack\CurrencyConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Imdhemy\Purchases\Facades\Product;

class GooglePlayPurchaseController extends Controller
{
    // Google Play takes 15% for subscriptions after year 1; 30% standard.
    // We use 30% conservatively so author earnings are never overstated.
    private const GOOGLE_COMMISSION   = 0.30;
    private const AUTHOR_REVENUE_SHARE = 0.75;

    /**
     * Verify a Google Play in-app purchase.
     *
     * Mobile sends:
     *  - purchase_token  : string (from BillingClient.queryPurchasesAsync)
     *  - product_id      : string (SKU, matches books.product_id)
     *  - purchase_type   : "book"|"audio" (optional, default "book")
     *
     * Google Play verification uses the imdhemy/laravel-purchases library,
     * which requires GOOGLE_PLAY_PACKAGE_NAME + service account JSON configured
     * in config/liap.php under 'google_play'.
     */
    public function verifyPurchase(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'purchase_token' => 'required|string',
            'product_id'     => 'required|string',
            'purchase_type'  => 'nullable|string|in:book,audio',
            'book_id'        => 'nullable|integer',
        ]);

        $purchaseToken     = $request->input('purchase_token');
        $productId         = $request->input('product_id');
        $purchaseType      = $request->input('purchase_type', 'book');
        $bookIdFromRequest = (int) $request->input('book_id', 0);

        Log::info('Google Play IAP verification started', [
            'user_id'    => $user->id,
            'product_id' => $productId,
            'book_id'    => $bookIdFromRequest,
        ]);

        try {
            // Verify with Google Play Developer API via imdhemy library
            $verification = Product::googlePlay()
                ->id($productId)
                ->token($purchaseToken)
                ->verify();

            if (! $verification->isValid()) {
                Log::warning('Google Play IAP: invalid purchase', [
                    'user_id'    => $user->id,
                    'product_id' => $productId,
                ]);
                return $this->error('Invalid or already-consumed purchase token.', 400);
            }

            $orderId = $verification->getTransactionId();

            return DB::transaction(function () use ($user, $productId, $orderId, $purchaseType, $verification, $bookIdFromRequest) {
                $bookPurchaseService = app(BookPurchaseService::class);

                $book = Book::where('product_id', $productId)
                    ->orWhere('audio_product_id', $productId)
                    ->first();

                // Tier lookup: shared price-tier SKUs (new books)
                if (! $book && IAPTierService::isTierSku($productId) && $bookIdFromRequest) {
                    $book = Book::find($bookIdFromRequest);
                }

                if (! $book) {
                    Log::warning("Google Play IAP: book not found for product_id: {$productId}", ['user_id' => $user->id]);
                    return $this->error("No book found matching product ID: {$productId}", 404);
                }

                // Idempotency: don't create duplicate transactions
                $existingTxn = Transaction::where('payment_intent_id', $orderId)->first();
                if (! $existingTxn) {
                    $amount = (float) ($book->actual_price ?? $book->discounted_price ?? 0);
                    Transaction::create([
                        'id'               => Str::uuid(),
                        'reference'        => uniqid('gplay_'),
                        'user_id'          => $user->id,
                        'payment_intent_id'=> $orderId,
                        'amount'           => $amount,
                        'currency'         => 'usd',
                        'payment_provider' => 'google_play',
                        'description'      => "Google Play {$purchaseType} purchase: {$book->title}",
                        'purpose_type'     => 'Online book purchase',
                        'status'           => 'success',
                        'type'             => 'purchase',
                        'direction'        => 'debit',
                        'meta_data'        => [
                            'product_id'    => $productId,
                            'book_id'       => $book->id,
                            'purchase_type' => $purchaseType,
                            'order_id'      => $orderId,
                        ],
                    ]);
                }

                // Grant book to library
                $granted = false;
                if (! $user->purchasedBooks()->where('books.id', $book->id)->exists()) {
                    $bookPurchaseService->addBooksToUserLibrary($user, [$book->id]);
                    $granted = true;
                }

                // Credit author(s) with pending IAP earnings
                if ($granted) {
                    $rateAtSale = null;
                    try {
                        $rateAtSale = app(CurrencyConversionService::class)->getExchangeRate('USD', 'NGN');
                    } catch (\Throwable) {
                        $rateAtSale = (float) config('services.currency.ngn_usd_fallback', 1600);
                    }

                    $price         = (float) ($book->actual_price ?? $book->discounted_price ?? 0);
                    $netFromGoogle = $price * (1 - self::GOOGLE_COMMISSION);
                    $authorEarning = round($netFromGoogle * self::AUTHOR_REVENUE_SHARE, 2);

                    foreach ($book->authors ?? [] as $author) {
                        $creditKey = 'gplay_author_' . $user->id . '_' . $book->id;

                        if (Transaction::where('payment_intent_id', $creditKey)->where('user_id', $author->id)->exists()) {
                            continue;
                        }

                        Transaction::create([
                            'id'               => Str::uuid(),
                            'user_id'          => $author->id,
                            'reference'        => uniqid('gplay_earn_'),
                            'payment_intent_id'=> $creditKey,
                            'amount'           => $authorEarning,
                            'currency'         => 'USD',
                            'payment_provider' => 'google_play',
                            'status'           => 'iap_pending',
                            'type'             => 'earning',
                            'direction'        => 'credit',
                            'description'      => "Google Play sale: {$book->title} (pending Google remittance)",
                            'purpose_type'     => 'iap_book_purchase',
                            'purpose_id'       => $book->id,
                            'meta_data'        => [
                                'buyer_id'         => $user->id,
                                'book_id'          => $book->id,
                                'gross_price'      => $price,
                                'google_cut'       => round($price * self::GOOGLE_COMMISSION, 2),
                                'net_from_google'  => round($netFromGoogle, 2),
                                'author_earning'   => $authorEarning,
                                'ngn_rate_at_sale' => $rateAtSale,
                            ],
                        ]);

                        Log::info('Google Play IAP: author pending earning created', [
                            'author_id'  => $author->id,
                            'book_id'    => $book->id,
                            'amount_usd' => $authorEarning,
                        ]);
                    }
                }

                Log::info('Google Play IAP: verification complete', [
                    'user_id'  => $user->id,
                    'book_id'  => $book->id,
                    'granted'  => $granted,
                ]);

                return $this->success([
                    'book'    => ['id' => $book->id, 'title' => $book->title],
                    'granted' => $granted,
                ], $granted ? 'Purchase verified successfully.' : 'Book is already in your library.');
            });
        } catch (\Throwable $e) {
            Log::error('Google Play IAP exception: ' . $e->getMessage(), [
                'user_id'    => $user->id,
                'product_id' => $productId,
                'trace'      => $e->getTraceAsString(),
            ]);
            return $this->error('Purchase verification failed. Please try again.', 500);
        }
    }
}
