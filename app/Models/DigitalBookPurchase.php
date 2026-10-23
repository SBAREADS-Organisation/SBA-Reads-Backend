<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Services\Dashboard\DashboardCacheService;


class DigitalBookPurchase extends Model
{
    protected $fillable = [
        'user_id',
        'total_amount',
        'currency',
        'stripe_payment_intent_id',
        'status',
        'platform_fee_amount',
        'discount_code_id',
    ];

    protected $casts = [
        'total_amount' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DigitalBookPurchaseItem::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    protected static function booted()
    {
        static::created(function ($purchase) {
            DashboardCacheService::clearAdminDashboard();
        });

        static::updated(function ($purchase) {
            DashboardCacheService::clearAdminDashboard();
            try {
                foreach ($purchase->items as $item) {
                    foreach (($item->book?->authors ?? []) as $author) {
                        DashboardCacheService::clearAuthorDashboard($author->id);
                    }
                }
            } catch (\Throwable) {
                // Non-fatal: cache will expire naturally
            }
        });
    }
}
