<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Models\AudioBookmark;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AudioBookmarkController extends Controller
{
    use ApiResponse;

    public function index(Request $request, int $bookId)
    {
        $user = $request->user();

        $bookmarks = AudioBookmark::where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->orderBy('created_at', 'desc')
            ->get(['id', 'segment_idx', 'position_ms', 'chapter_idx', 'chapter_title', 'note', 'created_at']);

        return $this->success(['bookmarks' => $bookmarks]);
    }

    public function store(Request $request, int $bookId)
    {
        $validator = Validator::make($request->all(), [
            'segment_idx'   => 'required|integer|min:0',
            'position_ms'   => 'required|integer|min:0',
            'chapter_idx'   => 'nullable|integer|min:0',
            'chapter_title' => 'nullable|string|max:255',
            'note'          => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $user = $request->user();

        $count = AudioBookmark::where('user_id', $user->id)->where('book_id', $bookId)->count();
        if ($count >= 200) {
            return $this->error('Bookmark limit reached. Delete some before adding more.', 422);
        }

        $bookmark = AudioBookmark::create([
            'user_id'       => $user->id,
            'book_id'       => $bookId,
            'segment_idx'   => $request->segment_idx,
            'position_ms'   => $request->position_ms,
            'chapter_idx'   => $request->chapter_idx,
            'chapter_title' => $request->chapter_title,
            'note'          => $request->note,
        ]);

        return $this->success(['bookmark' => $bookmark], 'Bookmark saved', 201);
    }

    public function destroy(Request $request, int $bookId, int $bookmarkId)
    {
        $user = $request->user();

        $bookmark = AudioBookmark::where('id', $bookmarkId)
            ->where('user_id', $user->id)
            ->where('book_id', $bookId)
            ->first();

        if (!$bookmark) {
            return $this->error('Bookmark not found', 404);
        }

        $bookmark->delete();

        return $this->success(null, 'Bookmark removed');
    }
}
