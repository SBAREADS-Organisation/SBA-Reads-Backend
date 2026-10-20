<?php

namespace App\Http\Controllers\Book;

use App\Http\Controllers\Controller;
use App\Models\BookDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookDraftController extends Controller
{
    public function get(Request $request): JsonResponse
    {
        $draft = BookDraft::where('user_id', $request->user()->id)->first();

        if (! $draft) {
            return $this->success(null, 'No draft found', 200);
        }

        return $this->success(
            ['draft' => $draft->data, 'saved_at' => $draft->updated_at->toIso8601String()],
            'Draft retrieved',
            200
        );
    }

    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'data'                   => 'required|array',
            'data.primaryData'       => 'required|array',
            'data.primaryData.title' => 'required|string|max:500',
        ]);

        $draft = BookDraft::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['data'    => $request->input('data')]
        );

        return $this->success(
            ['saved_at' => $draft->updated_at->toIso8601String()],
            'Draft saved',
            200
        );
    }

    public function delete(Request $request): JsonResponse
    {
        BookDraft::where('user_id', $request->user()->id)->delete();

        return $this->success(null, 'Draft deleted', 200);
    }
}
