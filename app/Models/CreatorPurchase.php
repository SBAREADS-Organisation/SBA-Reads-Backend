<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatorPurchase extends Model
{
    protected $fillable = [
        'product_id', 'store_id', 'buyer_id', 'price', 'fee', 'amount_paid', 'creator_earning',
        'fee_payer', 'plan', 'currency', 'status', 'paid_at', 'ticket_code', 'checked_in_at',
        'slot_id', 'access_until', 'renew_reminded_at', 'ended_notified_at',
        'discount_id', 'discount', 'affiliate_id', 'affiliate_earning',
        'auto_renew', 'card_id', 'renewal_attempted_at', 'renewal_failed_at', 'parent_purchase_id',
        'instalment_count', 'instalments_paid', 'instalment_status', 'next_instalment_at', 'instalment_failures',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'fee' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'creator_earning' => 'decimal:2',
            'paid_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'access_until' => 'datetime',
            'discount' => 'decimal:2',
            'affiliate_earning' => 'decimal:2',
            'renew_reminded_at' => 'datetime',
            'ended_notified_at' => 'datetime',
            'auto_renew' => 'boolean',
            'renewal_attempted_at' => 'datetime',
            'renewal_failed_at' => 'datetime',
            'next_instalment_at' => 'datetime',
            'instalment_count' => 'integer',
            'instalments_paid' => 'integer',
            'instalment_failures' => 'integer',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(CreatorSavedCard::class, 'card_id');
    }

    /** Products added at checkout as an upsell, paid for in this purchase's payment. */
    public function addOns(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CreatorPurchase::class, 'parent_purchase_id');
    }

    public function isInstalments(): bool
    {
        return (int) $this->instalment_count >= 2;
    }

    /** What instalment number k (1-based) costs; the last one takes any rounding remainder. */
    public function instalmentPart(float $total, int $k): float
    {
        $n = max(1, (int) $this->instalment_count);
        $each = floor($total / $n * 100) / 100;

        return $k < $n ? $each : round($total - $each * ($n - 1), 2);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(CreatorSlot::class, 'slot_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CreatorProduct::class, 'product_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(CreatorStore::class, 'store_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }
}
