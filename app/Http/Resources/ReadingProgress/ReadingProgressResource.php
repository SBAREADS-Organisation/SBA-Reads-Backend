<?php

namespace App\Http\Resources\ReadingProgress;

use App\Http\Resources\Book\BookResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadingProgressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'progress' => $this->progress,
            'page' => $this->page,
            'bookmarks' => $this->bookmarks,
            'last_accessed' => $this->last_accessed,
            'session_duration' => $this->session_duration,
            'book' => new BookResource($this->whenLoaded('book')),
        ];
    }
}
