<?php

namespace App\Services\Book\Audit;

use App\Models\Book;
use App\Models\BookAudit;
use App\Models\User;
use App\Traits\ApiResponse;

class BookAuditService
{
    use ApiResponse;

    public function create(Book $book, User $admin, string $action, ?string $note = null)
    {
        $book->update(['approval_status' => $action === 'requested_changes' ? 'needs_changes' : $action]);

        return BookAudit::create([
            'book_id' => $book->id,
            'admin_id' => $admin->id,
            'action' => $action,
            'note' => $note,
            'acted_at' => now(),
        ]);
    }

    /**
     * Get all BookAudits for a given book_id, including the book_id.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAuditsByBookId(int $bookId)
    {
        return BookAudit::where('book_id', $bookId)
            ->with('book:id') // eager load book with only id
            ->get();
    }

    /**
     * Update the status of a book with role-based restrictions.
     *
     * @return bool
     */
    public function updateBookStatus(Book $book, User $user, string $status)
    {
        // Define allowed statuses for author and admin
        $authorAllowed = ['draft', 'submitted'];
        $adminAllowed = ['approved', 'rejected', 'requested_changes', 'declined'];

        if ($user->id === $book->author_id) {
            // Author can only set allowed statuses
            if (! in_array($status, $authorAllowed)) {
                return $this->error('Unauthorized status change.');
            }
        } elseif ($user->is_admin) {
            // Admin can only set allowed statuses
            if (! in_array($status, $adminAllowed)) {
                return $this->error('Unauthorized status change.');
            }
        } else {
            // Neither author nor admin
            return $this->error('Unauthorized status change.');
        }

        $book->approval_status = $status;
        $book->save();

        return $this->success($book, 'Successful!!!');
    }
}
