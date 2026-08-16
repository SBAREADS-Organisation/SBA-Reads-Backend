<?php

namespace App\Services\Book;

use App\Models\Book;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookPurchaseService
{
    /**
     * Add purchased books to the user's library AND to all linked accounts.
     *
     * Dual-account users (same email, reader + author account types) link their
     * accounts in the linked_accounts table. IAP receipts are validated against
     * whichever account type the device has a token for — without this cross-grant,
     * a purchase made while logged in as author never appears in the reader library.
     */
    public function addBooksToUserLibrary(User $user, array $bookIds): bool
    {
        try {
            return DB::transaction(function () use ($user, $bookIds) {
                // Grant to the purchasing account.
                $this->grantBooksToUser($user, $bookIds);

                // Grant to every linked account (reader ↔ author pairing), looking
                // up the link in both directions so it works regardless of which
                // account type initiated the purchase.
                $linkedUserIds = LinkedAccount::where('user_id', $user->id)
                    ->orWhere('linked_user_id', $user->id)
                    ->get()
                    ->map(fn ($link) => $link->user_id === $user->id
                        ? $link->linked_user_id
                        : $link->user_id)
                    ->unique()
                    ->values();

                foreach ($linkedUserIds as $linkedUserId) {
                    $linkedUser = User::find($linkedUserId);
                    if ($linkedUser) {
                        $this->grantBooksToUser($linkedUser, $bookIds);
                    }
                }

                return true;
            });
        } catch (\Exception $e) {
            Log::error('addBooksToUserLibrary failed — book_user row NOT inserted', [
                'user_id'  => $user->id,
                'book_ids' => $bookIds,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function grantBooksToUser(User $user, array $bookIds): void
    {
        $existing = $user->purchasedBooks()
            ->whereIn('books.id', $bookIds)
            ->pluck('books.id')
            ->toArray();

        $newBooks = array_diff($bookIds, $existing);

        if (! empty($newBooks)) {
            $user->purchasedBooks()->syncWithoutDetaching($newBooks);
            Log::info('addBooksToUserLibrary: granted', [
                'user_id'  => $user->id,
                'book_ids' => $newBooks,
            ]);
        }
    }

    /**
     * Verify book ownership
     */
    public function userOwnsBook(User $user, int $bookId): bool
    {
        return $user->purchasedBooks()->where('book_id', $bookId)->exists();
    }
}
