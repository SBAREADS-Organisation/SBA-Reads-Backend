<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DigitalBookPurchase extends Model
{
    protected $fillable = [
        'user_id', // The reader who placed the order
        'total_amount', // Total amount of the entire order
        'currency',
        'stripe_payment_intent_id',
        'status', // 'pending', 'paid', 'failed'
        'platform_fee_amount', // Platform fee amount charged for the purchase
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
}
