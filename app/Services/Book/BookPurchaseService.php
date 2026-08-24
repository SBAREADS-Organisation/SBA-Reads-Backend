<?php

namespace App\Services\Book;

use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookPurchaseService
{
    /**
     * Grant purchased books to the correct reader account(s).
     *
     * Books are readable only on reader accounts — author accounts have no
     * library or study-room access. This method resolves the canonical reader
     * account(s) for the purchaser and grants exclusively there.
     *
     * Called by: Apple IAP, Google Play IAP, Stripe webhook, Paystack webhook,
     * BookController auto-heal on read, and the library:heal artisan command.
     * All callers remain unchanged — the routing logic is fully contained here.
     */
    public function addBooksToUserLibrary(User $user, array $bookIds): bool
    {
        try {
            return DB::transaction(function () use ($user, $bookIds) {
                foreach ($this->resolveReaderAccounts($user) as $reader) {
                    $this->grantBooksToUser($reader, $bookIds);
                }

                return true;
            });
        } catch (\Exception $e) {
            Log::error('addBooksToUserLibrary failed — book_user row NOT inserted', [
                'purchaser_id' => $user->id,
                'book_ids'     => $bookIds,
                'error'        => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check whether a user owns a book, across all linked accounts.
     *
     * An author checking a book that lives on their linked reader account
     * correctly returns true. Use this instead of $user->purchasedBooks()
     * wherever ownership must be account-type-agnostic.
     */
    public function userOwnsBook(User $user, int $bookId): bool
    {
        $allIds = $this->resolveAllLinkedAccountIds($user);

        return DB::table('book_user')
            ->whereIn('user_id', $allIds)
            ->where('book_id', $bookId)
            ->exists();
    }

    /**
     * Resolve the reader account(s) that should receive granted books.
     *
     * - Reader purchasing: returns the reader themselves.
     * - Author purchasing: returns their linked reader account(s).
     * - Author with no linked reader account: returns the author as a safe
     *   fallback so no payment is ever stranded.
     *
     * Public so callers (e.g. HealBookLibrary) can reuse the logic without
     * duplicating the linked-account query.
     */
    public function resolveReaderAccounts(User $user): Collection
    {
        if ($user->isReader()) {
            return collect([$user]);
        }

        $linkedIds = $this->resolveAllLinkedAccountIds($user);
        $readers = User::whereIn('id', $linkedIds)
            ->where('account_type', 'reader')
            ->get();

        if ($readers->isEmpty()) {
            Log::warning('BookPurchaseService: no reader account found — granting to purchaser as fallback', [
                'purchaser_id' => $user->id,
                'account_type' => $user->account_type,
            ]);
            return collect([$user]);
        }

        return $readers;
    }

    /**
     * Return every user ID in the link group: the given user plus all accounts
     * linked to them in either direction.
     */
    private function resolveAllLinkedAccountIds(User $user): array
    {
        $rows = LinkedAccount::where('user_id', $user->id)
            ->orWhere('linked_user_id', $user->id)
            ->get(['user_id', 'linked_user_id']);

        return $rows
            ->flatMap(fn ($link) => [$link->user_id, $link->linked_user_id])
            ->push($user->id)
            ->unique()
            ->values()
            ->toArray();
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
            Log::info('BookPurchaseService: books granted', [
                'reader_id' => $user->id,
                'book_ids'  => $newBooks,
            ]);
        }
    }
}
