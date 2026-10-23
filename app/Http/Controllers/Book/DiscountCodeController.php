<?php

namespace App\Http\Controllers\Book;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\DigitalBookPurchase;
use App\Models\DiscountCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DiscountCodeController extends Controller
{
    /**
     * Author/admin: create a discount code for a specific book.
     */
    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'code'      => 'required|string|max:64|unique:discount_codes,code',
            'type'      => 'required|in:free,percent,fixed',
            'value'     => 'required_unless:type,free|numeric|min:0',
            'max_uses'  => 'nullable|integer|min:1',
            'expires_at'=> 'nullable|date|after:now',
        ]);

        $user = $request->user();

        // Only the book's author or an admin may create codes for this book
        $isOwner = $book->author_id === $user->id
            || $book->authors()->where('users.id', $user->id)->exists();
        $isAdmin = $user->hasRole(['admin', 'superadmin']);

        if (! $isOwner && ! $isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $code = DiscountCode::create([
            'book_id'    => $book->id,
            'code'       => strtoupper(trim($data['code'])),
            'type'       => $data['type'],
            'value'      => $data['type'] === 'free' ? 0 : (float) ($data['value'] ?? 0),
            'max_uses'   => $data['max_uses'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json(['code' => 200, 'data' => $code], 200);
    }

    /**
     * Author/admin: list all discount codes for a book.
     */
    public function index(Request $request, Book $book): JsonResponse
    {
        $user = $request->user();

        $isOwner = $book->author_id === $user->id
            || $book->authors()->where('users.id', $user->id)->exists();
        $isAdmin = $user->hasRole(['admin', 'superadmin']);

        if (! $isOwner && ! $isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $codes = DiscountCode::where('book_id', $book->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['code' => 200, 'data' => $codes], 200);
    }

    /**
     * Author/admin: update a discount code (type, value, max_uses, expires_at, is_active).
     * The code string itself is immutable.
     */
    public function update(Request $request, DiscountCode $discountCode): JsonResponse
    {
        $user = $request->user();

        $book = $discountCode->book;
        $isOwner = $book && (
            $book->author_id === $user->id
            || $book->authors()->where('users.id', $user->id)->exists()
        );
        $isAdmin = $user->hasRole(['admin', 'superadmin']);

        if (! $isOwner && ! $isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $data = $request->validate([
            'type'       => 'sometimes|in:free,percent,fixed',
            'value'      => 'sometimes|numeric|min:0',
            'max_uses'   => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'is_active'  => 'sometimes|boolean',
        ]);

        $type = $data['type'] ?? $discountCode->type;

        $discountCode->update([
            'type'       => $type,
            'value'      => $type === 'free' ? 0.0 : (float) ($data['value'] ?? $discountCode->value),
            'max_uses'   => array_key_exists('max_uses', $data) ? $data['max_uses'] : $discountCode->max_uses,
            'expires_at' => array_key_exists('expires_at', $data) ? $data['expires_at'] : $discountCode->expires_at,
            'is_active'  => $data['is_active'] ?? $discountCode->is_active,
        ]);

        return response()->json(['code' => 200, 'data' => $discountCode->fresh()], 200);
    }

    /**
     * Author/admin: delete a discount code.
     */
    public function destroy(Request $request, DiscountCode $discountCode): JsonResponse
    {
        $user = $request->user();

        $book = $discountCode->book;
        $isOwner = $book && (
            $book->author_id === $user->id
            || $book->authors()->where('users.id', $user->id)->exists()
        );
        $isAdmin = $user->hasRole(['admin', 'superadmin']);

        if (! $isOwner && ! $isAdmin) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $discountCode->delete();

        return response()->json(['code' => 200, 'message' => 'Discount code deleted.'], 200);
    }

    /**
     * Public (auth required): apply a discount code to a book.
     * Returns the discounted price, or grants the book for free if type=free.
     */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code'    => 'required|string|max:64',
            'book_id' => 'required|integer|exists:books,id',
        ]);

        $code = DiscountCode::where('code', strtoupper(trim($data['code'])))
            ->where(function ($q) use ($data) {
                $q->where('book_id', $data['book_id'])
                  ->orWhereNull('book_id'); // global codes work on any book
            })
            ->first();

        if (! $code) {
            return response()->json(['message' => 'Invalid discount code.'], 422);
        }

        if (! $code->isUsable()) {
            return response()->json(['message' => 'This code has expired or reached its usage limit.'], 422);
        }

        $book = Book::findOrFail($data['book_id']);
        $originalPrice = (float) $book->actual_price;

        $finalPrice = match ($code->type) {
            'free'    => 0.0,
            'percent' => max(0, $originalPrice - ($originalPrice * $code->value / 100)),
            'fixed'   => max(0, $originalPrice - $code->value),
        };

        // For free codes (or codes that make it $0): grant or pre-order the book
        if ($finalPrice <= 0.0) {
            $user = $request->user();

            if ($user) {
                $alreadyOwned = DB::table('book_user')
                    ->where('user_id', $user->id)
                    ->where('book_id', $book->id)
                    ->exists();

                // Preorder: book not yet released — record claim but defer library access
                $isUnreleasedPreorder = $book->is_preorder
                    && $book->release_at
                    && $book->release_at->isFuture();

                if (! $alreadyOwned) {
                    if ($isUnreleasedPreorder) {
                        // Record the preorder claim so the scheduler can grant access on release
                        $alreadyPreordered = DigitalBookPurchase::where('user_id', $user->id)
                            ->where('status', 'preordered')
                            ->whereHas('items', fn ($q) => $q->where('book_id', $book->id))
                            ->exists();

                        if (! $alreadyPreordered) {
                            $purchase = DigitalBookPurchase::create([
                                'user_id'             => $user->id,
                                'total_amount'        => 0.0,
                                'platform_fee_amount' => 0.0,
                                'currency'            => 'USD',
                                'status'              => 'preordered',
                                'discount_code_id'    => $code->id,
                            ]);
                            $purchase->items()->create([
                                'book_id'                  => $book->id,
                                'author_id'                => $book->author_id,
                                'quantity'                 => 1,
                                'price_at_purchase'        => 0.0,
                                'price_at_purchase_usd'    => 0.0,
                                'author_payout_amount'     => 0.0,
                                'author_payout_amount_usd' => 0.0,
                                'platform_fee_amount'      => 0.0,
                                'platform_fee_amount_usd'  => 0.0,
                                'payout_status'            => 'pending',
                                'currency'                 => 'USD',
                            ]);
                            $code->increment('used_count');
                        }

                        return response()->json([
                            'code'           => 200,
                            'granted'        => false,
                            'preordered'     => true,
                            'release_at'     => $book->release_at->toISOString(),
                            'final_price'    => 0.0,
                            'original_price' => $originalPrice,
                            'type'           => $code->type,
                            'discount_value' => $code->value,
                        ]);
                    }

                    // Normal grant: book is available now
                    DB::table('book_user')->insertOrIgnore([
                        'user_id'    => $user->id,
                        'book_id'    => $book->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    // Record the free acquisition so authors can see download counts
                    $freePurchase = DigitalBookPurchase::create([
                        'user_id'             => $user->id,
                        'total_amount'        => 0.0,
                        'platform_fee_amount' => 0.0,
                        'currency'            => 'USD',
                        'status'              => 'paid',
                        'discount_code_id'    => $code->id,
                    ]);
                    $freePurchase->items()->create([
                        'book_id'                  => $book->id,
                        'author_id'                => $book->author_id,
                        'quantity'                 => 1,
                        'price_at_purchase'        => 0.0,
                        'price_at_purchase_usd'    => 0.0,
                        'author_payout_amount'     => 0.0,
                        'author_payout_amount_usd' => 0.0,
                        'platform_fee_amount'      => 0.0,
                        'platform_fee_amount_usd'  => 0.0,
                        'payout_status'            => 'pending',
                        'currency'                 => 'USD',
                    ]);
                    $code->increment('used_count');
                }
            }

            return response()->json([
                'code'           => 200,
                'granted'        => true,
                'preordered'     => false,
                'final_price'    => 0.0,
                'original_price' => $originalPrice,
                'type'           => $code->type,
                'discount_value' => $code->value,
            ]);
        }

        // Paid but discounted — return the adjusted price; usage is incremented
        // only when the purchase actually completes (books/purchase endpoint).
        return response()->json([
            'code'           => 200,
            'granted'        => false,
            'final_price'    => round($finalPrice, 2),
            'original_price' => $originalPrice,
            'type'           => $code->type,
            'discount_value' => $code->value,
        ]);
    }
}
