<?php

namespace App\Services\Book;

use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookPurchaseService
{
    /**
     * Add purchased books to user's library with retry mechanism
     */
    public function addBooksToUserLibrary(User $user, array $bookIds): bool
    {
        try {
            return DB::transaction(function () use ($user, $bookIds) {
                $existingBooks = $user->purchasedBooks()
                    ->whereIn('books.id', $bookIds)
                    ->pluck('books.id')
                    ->toArray();

                $newBooks = array_diff($bookIds, $existingBooks);

                if (!empty($newBooks)) {
                    $user->purchasedBooks()->syncWithoutDetaching($newBooks);

                    Log::info('Books added to user library', [
                        'user_id' => $user->id,
                        'books_added' => $newBooks,
                        'total_books' => count($newBooks)
                    ]);
                }

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Failed to add books to user library', [
                'user_id' => $user->id,
                'book_ids' => $bookIds,
                'error' => $e->getMessage()
            ]);
            return false;
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
