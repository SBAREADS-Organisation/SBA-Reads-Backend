<?php

namespace App\Http\Controllers\Book;

use App\Http\Controllers\Controller;
use App\Models\AudioBookPurchase;
use App\Models\Book;
use App\Models\ReadingProgress;
use App\Models\User;
use App\Services\Book\BookPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * A reader's place in a book, shared between the website and the mobile app.
 * Saving only touches the fields sent, so one device can't wipe what another saved.
 */
class ReadingStateController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $book = Book::find($id);
        if (! $book) {
            return $this->error('Book not found.', 404);
        }
        if (! $this->canAccess($request->user(), $book)) {
            return $this->error('You must purchase the book before reading it.', 403);
        }

        $record = ReadingProgress::where('user_id', $request->user()->id)->where('book_id', $id)->first();

        return $this->success($this->present($record), 'Reading state retrieved.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $book = Book::find($id);
        if (! $book) {
            return $this->error('Book not found.', 404);
        }
        if (! $this->canAccess($request->user(), $book)) {
            return $this->error('You must purchase the book before reading it.', 403);
        }

        $validator = Validator::make($request->all(), [
            'page' => 'sometimes|integer|min:1',
            'total_pages' => 'sometimes|integer|min:1',
            'bookmarks' => 'sometimes|array|max:2000',
            'bookmarks.*' => 'integer|min:1',
            'notes' => 'sometimes|array|max:2000',
            'notes.*' => 'string|max:5000',
            'audio_position' => 'sometimes|array',
            'audio_position.segment_index' => 'required_with:audio_position|integer|min:0',
            'audio_position.position_ms' => 'required_with:audio_position|integer|min:0',
        ]);
        if ($validator->fails()) {
            return $this->error('Please check your reading data and try again.', 422, $validator->errors());
        }

        $data = $validator->validated();
        $changes = ['last_accessed' => now()];

        if (isset($data['page'])) {
            $totalPages = (int) ($book->meta_data['pages'] ?? 0) ?: (int) ($data['total_pages'] ?? 0);
            $changes['page'] = (string) $data['page'];
            if ($totalPages > 0) {
                $changes['progress'] = min((int) round($data['page'] / $totalPages * 100), 100);
            }
        }
        if (array_key_exists('bookmarks', $data)) {
            $changes['page_bookmarks'] = array_values(array_unique($data['bookmarks']));
        }
        if (array_key_exists('notes', $data)) {
            $changes['notes'] = $data['notes'];
        }
        if (array_key_exists('audio_position', $data)) {
            $changes['audio_position'] = $data['audio_position'];
        }

        $record = ReadingProgress::updateOrCreate(
            ['user_id' => $request->user()->id, 'book_id' => $id],
            $changes
        );

        return $this->success($this->present($record), 'Reading state saved.');
    }

    private function canAccess(User $user, Book $book): bool
    {
        return $book->author_id === $user->id
            || $book->authors()->where('users.id', $user->id)->exists()
            || app(BookPurchaseService::class)->userOwnsBook($user, $book->id)
            || AudioBookPurchase::where('user_id', $user->id)->where('book_id', $book->id)->where('status', 'paid')->exists();
    }

    /** @return array<string, mixed> */
    private function present(?ReadingProgress $record): array
    {
        return [
            'page' => $record && (int) $record->page > 0 ? (int) $record->page : null,
            'bookmarks' => $record?->page_bookmarks,
            // Cast so an empty set stays a JSON object, the shape clients expect.
            'notes' => $record?->notes === null ? null : (object) $record->notes,
            'audio_position' => $record?->audio_position,
        ];
    }
}
