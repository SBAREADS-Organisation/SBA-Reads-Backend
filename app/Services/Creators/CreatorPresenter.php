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
            ];
        }

        return $data;
    }

    public function product(
        CreatorProduct $product,
        bool $private = false,
        bool $withLessons = false,
        bool $withFiles = false,
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
