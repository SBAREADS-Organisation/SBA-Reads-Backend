<?php

namespace App\Http\Resources\Book;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookResource extends JsonResource
{
    protected $isListing = false;

    public function __construct($resource, $isListing = false)
    {
        parent::__construct($resource);
        $this->isListing = $isListing;
    }

    public static function collection($resource, $isListing = false)
    {
        return parent::collection($resource)->map(function ($item) use ($isListing) {
            return new static($item->resource, $isListing);
        });
    }

    public function toArray($request)
    {
        $baseData = [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'cover_image' => $this->cover_image,
            'actual_price' => $this->actual_price,
            'discounted_price' => $this->discounted_price,
            'currency' => $this->currency,
            'format' => $this->format,
            'publisher' => $this->publisher,
            'publication_date' => $this->publication_date,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];

        if ($this->isListing) {
            return array_merge($baseData, [
                'average_rating' => round($this->reviews->avg('rating'), 1),
                'reviews_count' => $this->reviews->count(),
                'authors' => $this->authors->pluck('name'),
                'categories' => $this->categories->pluck('name'),
            ]);
        }

        // Full detail view - existing code
        return array_merge($baseData, [
            'sub_title' => $this->sub_title,
            'description' => $this->description,
            'isbn' => $this->isbn,
            'language' => $this->language,
            'tags' => $this->tags,
            'genres' => $this->genres,
            'table_of_contents' => $this->table_of_contents,
            'availability' => $this->availability,
            'drm_info' => $this->drm_info,
            'target_audience' => $this->target_audience,
            'meta_data' => $this->meta_data,
            'visibility' => $this->visibility,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'review_notes' => $this->review_notes,
            'expired_at' => $this->expired_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'archived' => $this->archived,
            'deleted' => $this->deleted,
            'author_id' => $this->author_id,
            'files' => $this->files,
            'categories' => $this->categories->map(function ($cat) {
                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                ];
            }),
            'reviews' => $this->reviews->map(function ($review) {
                return [
                    'id' => $review->id,
                    'user_id' => $review->user_id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at,
                    'user' => [
                        'id' => $review->user->id,
                        'name' => $review->user->name,
                        'email' => $review->user->email,
                        'profile_picture' => $review->user->profile_picture,
                    ],
                ];
            }),
            'authors' => $this->authors->map(function ($author) {
                $profilePicture = $author->profile_picture ?? [];
                $publicId = $profilePicture['public_url'] ?? ($profilePicture['public_id'] ?? null);
                $publicUrl = $profilePicture['public_id'] ?? ($profilePicture['public_url'] ?? null);
                $publicId = is_numeric($publicId) ? (int) $publicId : null;
                $publicUrl = is_string($publicUrl) ? $publicUrl : null;

                return [
                    'id' => $author->id,
                    'name' => $author->name,
                    'email' => $author->email,
                    'profile_picture' => [
                        'public_id' => $publicId,
                        'public_url' => $publicUrl,
                    ],
                    'bio' => $author->bio,
                ];
            }),
            'bookmarks' => $this->bookmarkedBy->pluck('id')->toArray(),
            'readers' => $this->purchasers->pluck('id')->toArray(),
        ]);
    }
}
