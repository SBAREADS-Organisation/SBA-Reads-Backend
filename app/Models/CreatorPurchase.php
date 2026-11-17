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
            'renew_reminded_at' => 'datetime',
            'ended_notified_at' => 'datetime',
        ];
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
