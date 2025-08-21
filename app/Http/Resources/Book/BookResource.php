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
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'sub_title' => $this->sub_title,
            'description' => $this->description,
            'isbn' => $this->isbn,
            'cover_image' => $this->cover_image,
            'language' => $this->language,
            'tags' => $this->tags,
            'genres' => $this->genres,
            'table_of_contents' => $this->table_of_contents,
            'publication_date' => $this->publication_date,
            'actual_price' => $this->actual_price,
            'discounted_price' => $this->discounted_price,
            'currency' => $this->currency,
            'format' => $this->format,
            'availability' => $this->availability,
            'drm_info' => $this->drm_info,
            'publisher' => $this->publisher,
            'target_audience' => $this->target_audience,
            'meta_data' => $this->meta_data,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'approved_at' => $this->approved_at,
            'approved_by' => $this->approved_by,
            'review_notes' => $this->review_notes,
            'expired_at' => $this->expired_at,
            'created_at' => $this->created_at,
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
            // Return all reviews and populate user_id with user information
            'reviews' => $this->reviews->map(function ($review) {
                return [
                    'id' => $review->id,
                    'user_id' => $review->user_id,
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'created_at' => $review->created_at,
                    'user' => $review->user ? [
                        'id' => $review->user->id,
                        'name' => $review->user->name,
                        'email' => $review->user->email,
                        'profile_picture' => $review->user->profile_picture,
                    ] : null,
                ];
            }),
            'average_rate' => $this->reviews->avg('rating') ?? 0,
            'authors' => $this->authors->map(function ($author) {
                return [
                    'id' => $author->id,
                    'name' => $author->name,
                    'email' => $author->email,
                    'profile_picture' => $author->profile_picture,
                    'bio' => $author->bio,
                ];
            }),
            'bookmarks' => $this->bookmarkedBy ? $this->bookmarkedBy->pluck('id')->toArray() : [],
            'readers' => $this->purchasers ? $this->purchasers->pluck('id')->toArray() : [],
        ];
    }
}
