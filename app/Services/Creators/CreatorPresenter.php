<?php

namespace App\Services\Creators;

use App\Models\CreatorLesson;
use App\Models\CreatorProduct;
use App\Models\CreatorStore;

/** The shape SBA Creators data is sent in. Private fields are only for the creator who owns it. */
class CreatorPresenter
{
    public function __construct(private CreatorService $service)
    {
    }

    public function store(CreatorStore $store, bool $private = false): array
    {
        $data = [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'bio' => $store->bio,
            'logo_url' => $store->logo_url,
            // Share of the creator's earning affiliates get, or null when the store has no affiliate programme.
            'affiliate_rate' => $store->affiliate_rate !== null ? (float) $store->affiliate_rate : null,
        ];

        if ($private) {
            $data += [
                'fee_payer' => $store->fee_payer,
                'plan' => $store->plan,
                'active_plan' => $store->activePlan(),
                'plan_paid_until' => $store->plan_paid_until?->toISOString(),
                'trial_ends_at' => $store->trial_ends_at?->toISOString(),
                'on_trial' => $store->onTrial(),
                'fee_rate' => $store->feeRate('NGN'),
                'suspended' => $store->suspended,
                'upload_limits_mb' => $store->uploadLimits(),
            ];
        }

        return $data;
    }

    public function product(
        CreatorProduct $product,
        bool $private = false,
        bool $withLessons = false,
        bool $withFiles = false,
        bool $withAccess = false,
    ): array {
        $data = [
            'id' => $product->id,
            'type' => $product->type,
            'title' => $product->title,
            'slug' => $product->slug,
            'summary' => $product->summary,
            'description' => $product->description,
            'cover_url' => $product->cover_url,
            'price' => (float) $product->price,
            'currency' => $product->currency,
            'published_at' => $product->published_at?->toISOString(),
            'store' => $product->relationLoaded('store') || $product->store ? $this->store($product->store) : null,
            'lessons_count' => $product->lessons_count ?? $product->lessons()->count(),
        ];

        if ($product->isEvent()) {
            $data += [
                'starts_at' => $product->starts_at?->toISOString(),
                'ends_at' => $product->ends_at?->toISOString(),
                'timezone' => $product->timezone,
                'location' => $product->location,
                'is_online' => (bool) $product->online_url,
                'capacity' => $product->capacity,
                'spots_left' => $product->spotsLeft(),
                'sales_closed' => $product->ticketSalesClosed(),
            ];
            // The joining link is for ticket holders and the creator only.
            if ($private || $withAccess) {
                $data['online_url'] = $product->online_url;
            }
        }

        if ($product->isCoaching()) {
            $data += [
                'duration_minutes' => $product->duration_minutes,
                'timezone' => $product->timezone,
                'location' => $product->location,
                'is_online' => (bool) $product->online_url,
                // The next free times, for the booking picker.
                'slots' => $product->slots()->open()->limit(200)->get(['id', 'starts_at'])
                    ->map(fn ($slot) => ['id' => $slot->id, 'starts_at' => $slot->starts_at->toISOString()])->values(),
            ];
            if ($private || $withAccess) {
                $data['online_url'] = $product->online_url;
            }
        }

        if ($product->isMembership()) {
            $data['period_days'] = (int) config('creators.plan_days');
            if ($private) {
                $data['active_members'] = $product->purchases()->where('status', 'paid')->where('access_until', '>', now())
                    ->distinct('buyer_id')->count('buyer_id');
            }
        }

        // Selling tools: instalments, the checkout add-on, and a bonus SBA ebook.
        if ($private) {
            $data += [
                'instalments_max' => $product->instalments_max,
                'upsell_product_id' => $product->upsell_product_id,
                'upsell_price' => $product->upsell_price !== null ? (float) $product->upsell_price : null,
                'bonus_book_id' => $product->bonus_book_id,
            ];
        } else {
            $data['instalments_max'] = $product->allowsInstalments() ? $product->instalments_max : null;
            if ($offer = $product->activeUpsell()) {
                $offerQuote = $this->service->quote($offer, null, null, (float) $product->upsell_price);
                $data['upsell'] = [
                    'id' => $offer->id, 'title' => $offer->title, 'type' => $offer->type, 'cover_url' => $offer->cover_url,
                    'list_price' => (float) $offer->price, 'price' => (float) $product->upsell_price, 'amount_to_pay' => $offerQuote['amount_paid'],
                ];
            }
        }
        $book = $product->bonus_book_id ? $product->bonusBook : null;
        if ($book && $book->status === 'approved') {
            $cover = is_array($book->cover_image) ? ($book->cover_image['public_url'] ?? $book->cover_image['url'] ?? null) : null;
            $data['bonus_book'] = ['id' => $book->id, 'title' => $book->title, 'cover_url' => $cover];
        }

        if (! $private) {
            $quote = $this->service->quote($product);
            $data['amount_to_pay'] = $quote['amount_paid'];
            $data['buyer_fee'] = $quote['fee_payer'] === 'buyer' ? $quote['fee'] : 0;
        } else {
            $data['status'] = $product->status;
            $data['sales_count'] = $product->sales_count ?? $product->purchases()->where('status', 'paid')->count();
        }

        if ($withLessons) {
            $data['lessons'] = $product->lessons->map(fn (CreatorLesson $lesson) => $this->lesson($lesson, $withFiles))->values();
        }

        return $data;
    }

    /** With access, includes the lesson's text and a short-lived link to its file. */
    public function lesson(CreatorLesson $lesson, bool $withAccess = false): array
    {
        $data = [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'kind' => $lesson->kind,
            'position' => $lesson->position,
            'is_preview' => $lesson->is_preview,
            'file_name' => $lesson->file_name,
            'file_size' => $lesson->file_size,
            'has_file' => (bool) $lesson->file_key,
        ];

        if ($withAccess) {
            $data['body'] = $lesson->body;
            $data['file_url'] = $this->service->fileUrl(
                $lesson->file_key,
                downloadName: $lesson->kind === 'file' ? ($lesson->file_name ?: 'download') : null,
            );
        }

        return $data;
    }
}
