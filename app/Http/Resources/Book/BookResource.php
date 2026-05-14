<?php

namespace App\Http\Resources\Book;

use App\Http\Resources\User\UserResource;
use App\Models\AudioBookPurchase;
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
            'product_id'       => $this->product_id,
            'audio_product_id' => $this->audio_product_id,
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
            'rejection_note' => $this->rejection_note,
            'expired_at' => $this->expired_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'archived' => $this->archived,
            'deleted' => $this->deleted,
            'stock_quantity' => $this->stock_quantity ?? 0,
            'stock_reserved' => $this->stock_reserved ?? 0,
            'stock_available' => max(0, ($this->stock_quantity ?? 0) - ($this->stock_reserved ?? 0)),
            'author_id' => $this->author_id,
            'files' => $this->files,
            'is_featured'    => (bool) ($this->is_featured ?? false),
            'ranking'        => $this->ranking,
            'audio_price'    => $this->audio_price ?? 10.00,
            'audio_status' => $this->audio_status ?? 'none',
            'audio_url' => $this->audio_url,
            'audio_sample_url' => $this->audio_sample_url,
            'audio_duration' => $this->audio_duration,
            'audio_segments' => $this->audio_segments ?? [],
            'audio_chapters' => $this->audio_chapters ?? [],
            'audio_purchased' => $this->resolveAudioPurchased(),
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
                        'profile_picture' => $this->formatProfilePicture($review->user->profile_picture ?? []),
                    ] : null,
                ];
            }),
            'average_rate' => $this->reviews->avg('rating') ?? 0,
            'authors' => UserResource::collection($this->whenLoaded('authors')),
            'author' => new UserResource($this->whenLoaded('author')),
            'bookmarks' => $this->bookmarkedBy ? $this->bookmarkedBy->pluck('id')->toArray() : [],
            'readers' => $this->purchasers ? $this->purchasers->pluck('id')->toArray() : [],
        ];
    }

    // Date audio became a separate purchase. Purchases before this are grandfathered.
    private const AUDIO_SPLIT_DATE = '2026-05-13';

    private function resolveAudioPurchased(): bool
    {
        $userId = auth()->id();
        if (!$userId) return false;

        try {
            // Paid audio purchase — always valid
            $hasAudioPurchase = AudioBookPurchase::where('user_id', $userId)
                ->where('book_id', $this->id)
                ->where('status', 'paid')
                ->exists();

            if ($hasAudioPurchase) return true;

            // Grandfathered: bought the book before audio was split into a separate product
            return \Illuminate\Support\Facades\DB::table('book_user')
                ->where('user_id', $userId)
                ->where('book_id', $this->id)
                ->where('created_at', '<', self::AUDIO_SPLIT_DATE)
                ->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function formatProfilePicture($profilePicture)
    {
        $rawId = $profilePicture['public_id'] ?? null;
        $rawUrl = $profilePicture['public_url'] ?? null;
        $publicId = is_numeric($rawId) ? (int) $rawId : null;
        $publicUrl = is_string($rawUrl) ? $rawUrl : null;

        return [
            'public_id' => (int) $publicId,
            'public_url' => (string) $publicUrl,
        ];
    }
}
